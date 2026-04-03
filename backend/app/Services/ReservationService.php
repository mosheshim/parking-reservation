<?php

namespace App\Services;

use App\Events\ParkingSlotStatusChanged;
use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\SlotService;
use App\ValueObjects\SlotAvailability;
use App\ValueObjects\SlotDefinition;
use App\ValueObjects\SlotTimeDefinition;
use App\ValueObjects\SpotSlotAvailability;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateException;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReservationService
{
    public function __construct(
        private readonly SlotService $slotService,
    ) {
    }

    /**
     * Return the timezone identifier used to interpret slot boundaries.
     * This exists because the internal timezone constant is private, but other layers still need the configured value.
     */
    public static function getSlotTimezone(): string
    {
        return app(SlotService::class)->getSlotTimezone();
    }

    /**
     * Return the raw slot time definitions (local-time boundaries only).
     * This exists so tests and other callers do not couple to internal constants.
     *
     * @return array<int, SlotTimeDefinition>
     */
    public function getSlotTimeDefinitions(): array
    {
        return $this->slotService->getSlotTimeDefinitions();
    }

    /**
     * Return slot definitions for a specific date/timezone, including precomputed UTC boundaries.
     * This exists so all consumers use one canonical mapping from local slot times to UTC.
     *
     * @return array<int, SlotDefinition>
     * @throws InvalidFormatException
     */
    public function getSlotDefinitionsForDate(Carbon $date, ?DateTimeZone $timezone = null): array
    {
        return $this->slotService->getSlotDefinitionsForDate($date, $timezone);
    }

    /**
     * Create a reservation for a user.
     *
     * Concurrency is enforced by a Postgres EXCLUDE constraint (see migration).
     * If a conflicting reservation exists, Postgres rejects the insert; we map it to a user-friendly error.
     *
     * @throws ReservationTimeConflictException When reservation overlaps an existing active reservation.
     * @throws ReservationTimeOutOfRangeException When reservation timestamps are outside allowed business hours or end in the past.
     * @throws QueryException When the database rejects the insert for any other reason.
     * @throws Throwable
     */
    public function createReservation(User $user, int $spotId, Carbon $startTimeUtc, Carbon $endTimeUtc): Reservation
    {
        if ($endTimeUtc->lte(Carbon::now('UTC'))) {
            throw new ReservationTimeOutOfRangeException(
                message: 'Reservation end time cannot be in the past'
            );
        }

        // Sending "now" start time from the frontend may be in the past until the server processes it or if someone modified request.
        // Clamp it to "now" to avoid creating a reservation that has already started.
        $startTimeUtc = $this->clampStartTimeToNowIfPast($startTimeUtc);

        $this->assertReservationRangeIsAllowed($startTimeUtc, $endTimeUtc, self::getDefaultTimezone());

        // Postgres aborts the current transaction on constraint violations (e.g. overlap EXCLUDE).
        // Wrapping the insert in its own transaction creates a savepoint under an outer transaction (like RefreshDatabase),
        // allowing the failed insert to roll back cleanly without poisoning subsequent queries.
        $reservation =  DB::transaction(function () use ($user, $spotId, $startTimeUtc, $endTimeUtc): Reservation {
            try {
                $reservation = new Reservation();
                $reservation->user_id = $user->id;
                $reservation->spot_id = $spotId;
                $reservation->start_time = $startTimeUtc->copy()->utc()->toDateTimeString();
                $reservation->end_time = $endTimeUtc->copy()->utc()->toDateTimeString();
                $reservation->status = Reservation::STATUS_BOOKED;
                $reservation->save();

                return $reservation;
            } catch (QueryException $e) {
                if ($this->isOverlapConstraintViolation($e)) {
                    throw new ReservationTimeConflictException(previous: $e);
                }

                throw $e;
            }
        });

        $timezone = self::getDefaultTimezone();
        $localDate = $startTimeUtc->copy()->utc()->setTimezone($timezone)->toDateString();
        $this->broadcastAvailabilityUpdateForReservation($reservation, $localDate);
        return $reservation;
    }

    /**
     * Broadcast the minimal set of slot updates impacted by a reservation change.
     *
     * This exists so realtime updates do not require recomputing (and broadcasting) the full daily snapshot.
     * Only slots whose UTC ranges overlap the reservation window are queried and emitted.
     *
     * @throws Throwable When slot time calculations fail.
     */
    protected function broadcastAvailabilityUpdateForReservation(Reservation $reservation, string $date): void
    {
        $spotAvailabilities = $this->getSpotSlotAvailabilityForReservation($reservation, $date);
        $this->broadcastSpotSlotAvailability($date, $spotAvailabilities);
    }

    /**
     * Compute slot availability updates for the reservation, restricted to the slot(s) overlapped by the reservation range.
     *
     * Reservations are stored in UTC; slot boundaries are built from the provided local date/timezone and then
     * converted to UTC so comparisons and overlap queries happen on the same timeline as the database.
     *
     * @return array<int, SpotSlotAvailability>
     * @throws DateException
     * @throws Throwable
     */
    public function getSpotSlotAvailabilityForReservation(Reservation $reservation, string $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $localDate = Carbon::parse($date, $timezone)->startOfDay();
        $slotDefinitions = $this->getSlotDefinitionsForDate($localDate, $timezone);

        $reservationStartUtc = $reservation->start_time->copy()->utc();
        $reservationEndUtc = $reservation->end_time->copy()->utc();

        // We only broadcast slot cells that overlap the reservation, so clients can patch their UI without downloading a full snapshot.
        $affectedSlotIndexes = $this->slotService->getOverlappingSlotIndexes($slotDefinitions, $reservationStartUtc, $reservationEndUtc);
        if ($affectedSlotIndexes === []) {
            return [];
        }

        // A booked reservation takes the slot; a completed reservation frees it.
        $isTaken = $reservation->status === Reservation::STATUS_BOOKED;
        $slots = [];
        foreach ($affectedSlotIndexes as $slotIndex) {
            $slotDefinition = $slotDefinitions[$slotIndex];

            $slots[] = new SlotAvailability(
                key: $slotDefinition->key,
                start: $slotDefinition->start,
                end: $slotDefinition->end,
                startUtc: $slotDefinition->startUtc->toISOString(),
                endUtc: $slotDefinition->endUtc->toISOString(),
                taken: $isTaken,
            );
        }

        return [new SpotSlotAvailability(
            id: (int) $reservation->spot_id,
            spotNumber: '',
            slots: $slots,
        )];
    }

    /**
     * Clamp a reservation start time to "now" when it is in the past.
     * This prevents creating a reservation that started before the current time.
     */
    private function clampStartTimeToNowIfPast(Carbon $startTimeUtc): Carbon
    {
        $nowUtc = Carbon::now('UTC');
        $normalizedStartUtc = $startTimeUtc->copy()->utc();

        if ($normalizedStartUtc->lt($nowUtc)) {
            return $nowUtc;
        }

        return $normalizedStartUtc;
    }

    /**
     * Ensure a requested reservation range is allowed.
     *
     * The API accepts UTC timestamps, but the business rule is defined in a local timezone:
     * reservations may only be created within the daily window of the first and last slot.
     *
     * For example: if slots ranges are 08:00 - 12:00, 12:00 - 16:00, 16:00 - 20:00.
     * Then the allowed range is 08:00 - 20:00.
     *
     * @throws ReservationTimeOutOfRangeException When the range is outside the allowed daily window or crosses local midnight.
     */
    protected function assertReservationRangeIsAllowed(Carbon $startTimeUtc, Carbon $endTimeUtc, DateTimeZone $timezone): void
    {
        $localStart = $startTimeUtc->copy()->utc()->setTimezone($timezone);
        $localEnd = $endTimeUtc->copy()->utc()->setTimezone($timezone);

        // Derive bounds from SlotService definitions.
        $slotTimeDefinitions = $this->getSlotTimeDefinitions();
        $firstSlot = $slotTimeDefinitions[0];
        $lastSlot = $slotTimeDefinitions[array_key_last($slotTimeDefinitions)];

        $allowedStart = $localStart->copy()
            ->startOfDay()
            ->setTimeFromTimeString($firstSlot->start);

        $allowedEnd = $localStart->copy()
            ->startOfDay()
            ->setTimeFromTimeString($lastSlot->end);

        // The UI requests a single slot on a single day; we reject cross-midnight ranges to keep slot math and overlap rules unambiguous.
        $isSameLocalDay = $localStart->toDateString() === $localEnd->toDateString();

        $isWithinAllowedWindow =
            $localStart->gte($allowedStart) &&
            $localEnd->lte($allowedEnd) &&
            $localEnd->gte($allowedStart);

        if ($isSameLocalDay && $isWithinAllowedWindow) {
            return;
        }

        throw new ReservationTimeOutOfRangeException(
            message: sprintf(
                'Reservation can only be in the following time range %s-%s',
                $firstSlot->start,
                $lastSlot->end
            ),
        );
    }

    /**
     * Mark a reservation as completed. Will update the completed_at column to now.
     *
     * @param int $reservationId
     * @throws DateException When the configured timezone identifier is invalid.
     * @throws ModelNotFoundException When the reservation does not exist.
     * @throws QueryException When the database rejects the update.
     * @throws Throwable
     */
    public function complete(int $reservationId): void
    {
        $reservation = Reservation::query()->findOrFail($reservationId);

        if ($reservation->status === Reservation::STATUS_COMPLETED) {
            return;
        }

        $completedAtUtc = Carbon::now('UTC')->toDateTimeString();
        Reservation::query()
            ->whereKey($reservationId)
            ->update([
                'status' => Reservation::STATUS_COMPLETED,
                'completed_at' => $completedAtUtc,
            ]);

        $timezone = self::getDefaultTimezone();
        $localDate = $reservation->start_time->copy()->utc()->setTimezone($timezone)->toDateString();
        $this->broadcastAvailabilityUpdateForReservation($reservation->refresh(), $localDate);
    }

    /**
     * Complete a batch of stale booked reservations that ended before the provided UTC moment.
     *
     * This encapsulates the DB locking and bulk update logic used by the background job so the job
     * remains responsible only for orchestration and logging while the service owns the data changes.
     *
     * @param CarbonInterface   $nowUtc    The reference time used to determine which reservations are stale.
     * @param int|null          $batchSize Maximum number of reservations to complete in this batch; when null, all matching reservations are completed.
     * @return Collection<int, Reservation> The reservations that were completed in this batch.
     * @throws QueryException When the database rejects the update.
     * @throws Throwable
     */
    public function completeStaleReservationsBatch(CarbonInterface $nowUtc, ?int $batchSize = null)
    {
        /** @var Collection<int, Reservation> $completedReservations */
        $completedReservations = DB::transaction(function () use ($batchSize, $nowUtc) {
            $query = Reservation::query()
                ->where('status', Reservation::STATUS_BOOKED)
                ->where('end_time', '<', $nowUtc)
                ->lockForUpdate();

            if ($batchSize !== null) {
                $query->limit($batchSize);
            }

            $rows = $query->get(['id', 'spot_id']);

            if ($rows->isEmpty()) {
                return $rows;
            }

            $ids = $rows->pluck('id');

            Reservation::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => Reservation::STATUS_COMPLETED,
                    'completed_at' => Carbon::now('UTC')->toDateTimeString(),
                ]);

            return $rows;
        });

        return $completedReservations;
    }

    /**
     * Return, for a given local date/timezone pair, which of the fixed daily slots are taken per parking spot.
     *
     * Reservations are stored in UTC; slot boundaries are built in the requested timezone and then converted to UTC
     *  so the overlap query compares the same timeline the database stores.
     *
     * @param Carbon $date The input date is copied before any mutation so callers keep their original Carbon instance unchanged.
     * @param DateTimeZone|null $timezone
     * @return array<int, SpotSlotAvailability>
     * @throws QueryException When reservation/spot queries fail.
     * @throws InvalidFormatException When slot timestamps cannot be built from the configured slot definitions.
     * @throws Throwable
     */
    public function getSlotAvailabilityForDate(Carbon $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $slotDefinitions = $this->getSlotDefinitionsForDate($date, $timezone);

        // Build a VALUES table of slot ranges so we can evaluate all overlaps in a single query.
        // This avoids N round-trips while still allowing PostgreSQL to use the GiST overlap operator per slot.
        $values = [];
        $bindings = [];

        foreach ($slotDefinitions as $index => $slotDefinition) {
            $values[] = "(?, tsrange(?, ?))";
            $bindings[] = $index;
            $bindings[] = $slotDefinition->startUtc->toDateTimeString();
            $bindings[] = $slotDefinition->endUtc->toDateTimeString();
        }

        $valuesSql = implode(", ", $values);

        // Single query:
        // - Joins reservations against the in-memory VALUES table of slot ranges
        // - Uses the GiST-backed overlap operator (&&)
        // - Returns (spot_id, slot_index) pairs for taken slots
        $rows = DB::select("
        SELECT DISTINCT r.spot_id, s.slot_index
        FROM reservations r
        JOIN (
            VALUES $valuesSql
        ) AS s(slot_index, slot_range)
        ON tsrange(r.start_time, r.end_time) && s.slot_range
        WHERE r.status = ?
    ", [...$bindings, Reservation::STATUS_BOOKED]);

        // Rebuild lookup map: [slot_index][spot_id] => true
        $takenSpotIdsBySlot = [];
        foreach ($rows as $row) {
            $takenSpotIdsBySlot[$row->slot_index][$row->spot_id] = true;
        }

        $spots = ParkingSpot::query()
            ->orderBy('id')
            ->get(['id', 'spot_number']);

        $result = [];

        // Build a map for all available/taken slots per spot
        foreach ($spots as $spot) {
            $slots = [];
            foreach ($slotDefinitions as $index => $slotDefinition) {
                $isTaken = isset($takenSpotIdsBySlot[$index][$spot->id]);

                $slots[] = new SlotAvailability(
                    key: $slotDefinition->key,
                    start: $slotDefinition->start,
                    end: $slotDefinition->end,
                    startUtc: $slotDefinition->startUtc->toISOString(),
                    endUtc: $slotDefinition->endUtc->toISOString(),
                    taken: $isTaken,
                );
            }

            $result[] = new SpotSlotAvailability(
                id: $spot->id,
                spotNumber: $spot->spot_number,
                slots: $slots,
            );
        }

        return $result;
    }

    /**
     * Build the default timezone object used for interpreting slot boundaries.
     *
     * A factory method is required because PHP constants cannot hold objects.
     *
     * @throws DateInvalidTimeZoneException When the configured timezone identifier is invalid.
     */
    public static function getDefaultTimezone(): DateTimeZone
    {
        return app(SlotService::class)->getDefaultTimezone();
    }

    /**
     * Detect Postgres EXCLUDE constraint errors for overlapping reservations.
     */
    protected function isOverlapConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // 23P01 = exclusion_constraint_violation (Postgres)
        if ($sqlState === '23P01') {
            return true;
        }

        $message = (string) $e->getMessage();
        return Str::contains($message, 'reservations_no_overlap_per_spot');
    }


    /**
     * Broadcast a set of spot slot updates to listeners of the given local date.
     *
     * @param array<int, SpotSlotAvailability> $spotAvailabilities
     */
    public function broadcastSpotSlotAvailability(string $date, array $spotAvailabilities): void
    {
        foreach ($spotAvailabilities as $spotAvailability) {
            if (!$spotAvailability instanceof SpotSlotAvailability) {
                continue;
            }

            foreach ($spotAvailability->slots as $slot) {
                event(new ParkingSlotStatusChanged(
                    date: $date,
                    spotId: $spotAvailability->id,
                    slotKey: $slot->key,
                    start: $slot->start,
                    end: $slot->end,
                    startUtc: $slot->startUtc,
                    endUtc: $slot->endUtc,
                    taken: $slot->taken,
                ));
            }
        }
    }
}
