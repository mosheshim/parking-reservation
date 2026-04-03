<?php

namespace App\Services;

use App\Events\ParkingSlotStatusChanged;
use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\ValueObjects\SlotAvailability;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReservationService
{

    // These constants can be moved to the DB so dynamic slots can be used (for exmaple if there will be multiple parking lots)
    private const SLOT_TIMEZONE = 'Asia/Jerusalem';
    public const SLOT_DEFINITIONS = [
        ['start' => '08:00', 'end' => '12:00'],
        ['start' => '12:00', 'end' => '16:00'],
        ['start' => '16:00', 'end' => '22:00'],
    ];

    /**
     * Return the timezone identifier used to interpret slot boundaries.
     * This exists because the internal timezone constant is private, but other layers still need the configured value.
     */
    public static function getSlotTimezone(): string
    {
        return self::SLOT_TIMEZONE;
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
     * @throws Throwable When slot time calculations fail.
     */
    public function getSpotSlotAvailabilityForReservation(Reservation $reservation, string $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $localDate = Carbon::parse($date, $timezone)->startOfDay();
        $slotDefinitions = self::SLOT_DEFINITIONS;
        $slotRangesUtc = $this->buildSlotRangesUtc($localDate, $timezone, $slotDefinitions);

        $reservationStartUtc = $reservation->start_time->copy()->utc();
        $reservationEndUtc = $reservation->end_time->copy()->utc();

        // We only broadcast slot cells that overlap the reservation, so clients can patch their UI without downloading a full snapshot.
        $affectedSlotIndexes = $this->getOverlappingSlotIndexes($slotRangesUtc, $reservationStartUtc, $reservationEndUtc);
        if ($affectedSlotIndexes === []) {
            return [];
        }

        // A booked reservation takes the slot; a completed reservation frees it.
        $isTaken = $reservation->status === Reservation::STATUS_BOOKED;
        $slots = [];
        foreach ($affectedSlotIndexes as $slotIndex) {
            $slotDefinition = $slotDefinitions[$slotIndex];
            $slotRangeUtc = $slotRangesUtc[$slotIndex];

            $slots[] = new SlotAvailability(
                key: $this->buildSlotKey($slotDefinition['start'], $slotDefinition['end']),
                start: $slotDefinition['start'],
                end: $slotDefinition['end'],
                startUtc: $slotRangeUtc['start']->toISOString(),
                endUtc: $slotRangeUtc['end']->toISOString(),
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
     * Identify which fixed daily slots overlap a given UTC reservation window.
     * This exists so we can restrict realtime queries/broadcasts to only impacted slot cells.
     *
     * For example: if slots ranges are 08:00 - 12:00, 12:00 - 16:00, 16:00 - 20:00.
     * And the reservation is 10:00 - 14:00.
     * Then the overlapping slots are 08:00 - 12:00 and 12:00 - 16:00.
     * The returned value will be the slot indexes [0, 1]
     *
     * @param array<int, array{start:Carbon, end:Carbon}> $slotRangesUtc
     * @return array<int, int>
     * @throws Throwable When Carbon comparisons fail.
     */
    protected function getOverlappingSlotIndexes(array $slotRangesUtc, Carbon $reservationStartUtc, Carbon $reservationEndUtc): array
    {
        $affected = [];
        foreach ($slotRangesUtc as $slotIndex => $slotRangeUtc) {
            $slotStartUtc = $slotRangeUtc['start'];
            $slotEndUtc = $slotRangeUtc['end'];

            // Overlap check: [a,b) intersects [c,d) iff a < d and b > c.
            if ($reservationStartUtc->lt($slotEndUtc) && $reservationEndUtc->gt($slotStartUtc)) {
                $affected[] = $slotIndex;
            }
        }

        return $affected;
    }

    /**
     * Clamp a reservation start time to "now" when it is in the past.
     * This prevents creating a reservation that started before the current time.
     *
     * @throws Throwable When Carbon cannot compute timestamps.
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
    private function assertReservationRangeIsAllowed(Carbon $startTimeUtc, Carbon $endTimeUtc, DateTimeZone $timezone): void {
        $localStart = $startTimeUtc->copy()->utc()->setTimezone($timezone);
        $localEnd = $endTimeUtc->copy()->utc()->setTimezone($timezone);

        // Derive bounds from SLOT_DEFINITIONS
        $firstSlot = self::SLOT_DEFINITIONS[0];
        $lastSlot = self::SLOT_DEFINITIONS[array_key_last(self::SLOT_DEFINITIONS)];

        $allowedStart = $localStart->copy()
            ->startOfDay()
            ->setTimeFromTimeString($firstSlot['start']);

        $allowedEnd = $localStart->copy()
            ->startOfDay()
            ->setTimeFromTimeString($lastSlot['end']);

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
                $firstSlot['start'],
                $lastSlot['end']
            ),
        );
    }

    /**
     * Mark a reservation as completed. Will update the completed_at column to now.
     *
     * @throws ModelNotFoundException When the reservation does not exist.
     * @throws QueryException When the database rejects the update.
     * @throws Throwable When date/time conversions fail.
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
     * Return, for a given local date/timezone pair, which of the fixed daily slots are taken per parking spot.
     *
     * Reservations are stored in UTC; slot boundaries are built in the requested timezone and then converted to UTC
     *  so the overlap query compares the same timeline the database stores.
     *
     * @param Carbon $date The input date is copied before any mutation so callers keep their original Carbon instance unchanged.
     * @param DateTimeZone|null $timezone
     * @return array<int, SpotSlotAvailability>
     * @throws QueryException When reservation/spot queries fail.
     * @throws Throwable When slot time calculations fail.
     */
    public function getSlotAvailabilityForDate(Carbon $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $slotDefinitions = self::SLOT_DEFINITIONS;
        $slotRangesUtc = $this->buildSlotRangesUtc($date, $timezone, $slotDefinitions);

        //todo check if can be run in one query.
        // Query each slot independently so the database can use the GiST-backed overlap operator directly.
        $takenSpotIdsBySlot = [];
        foreach ($slotRangesUtc as $slotIndex => $slotRangeUtc) {
            $takenSpotIds = Reservation::query()
                ->where('status', Reservation::STATUS_BOOKED)
                ->whereRaw(
                    'tsrange(start_time, end_time) && tsrange(?, ?)',
                    [$slotRangeUtc['start']->toDateTimeString(), $slotRangeUtc['end']->toDateTimeString()]
                )
                ->distinct()
                ->pluck('spot_id')
                ->all();

            $takenSpotIdsBySlot[$slotIndex] = array_fill_keys($takenSpotIds, true);
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

                $slotRangeUtc = $slotRangesUtc[$index];
                $startUtc = $slotRangeUtc['start']->toISOString();
                $endUtc = $slotRangeUtc['end']->toISOString();

                $slots[] = new SlotAvailability(
                    key: $this->buildSlotKey($slotDefinition['start'], $slotDefinition['end']),
                    start: $slotDefinition['start'],
                    end: $slotDefinition['end'],
                    startUtc: $startUtc,
                    endUtc: $endUtc,
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
     * Build a stable identifier for a slot based on its local-time boundaries.
     * This exists so the frontend can update a specific slot when receiving real-time events.
     *
     * @throws Throwable When string operations fail.
     */
    private function buildSlotKey(string $startLocalTime, string $endLocalTime): string
    {
        return $startLocalTime.' - '.$endLocalTime;
    }

    /**
     * Convert fixed local-time slot definitions into UTC ranges for a specific date and timezone.
     *
     * Each slot is built from a copied Carbon instance so the caller's date object is never mutated while we
     * create start/end timestamps and convert them to UTC for the overlap query.
     *
     * @param DateTimeZone $timezone The timezone used to interpret the local slot boundaries.
     * @param array<int, array{start:string, end:string}> $slotDefinitions
     * @return array<int, array{start:Carbon, end:Carbon}>
     * @throws Throwable When Carbon cannot parse or convert timestamps.
     */
    private function buildSlotRangesUtc(Carbon $date, DateTimeZone $timezone, array $slotDefinitions): array
    {
        $localDate = $date->copy()->setTimezone($timezone)->startOfDay();

        $ranges = [];
        foreach ($slotDefinitions as $slotDefinition) {
            $localStart = $localDate->copy()->setTimeFromTimeString($slotDefinition['start']);
            $localEnd = $localDate->copy()->setTimeFromTimeString($slotDefinition['end']);

            $ranges[] = [
                'start' => $localStart->copy()->utc(),
                'end' => $localEnd->copy()->utc(),
            ];
        }

        return $ranges;
    }

    /**
     * Build the default timezone object used for interpreting slot boundaries.
     *
     * A factory method is required because PHP constants cannot hold objects.
     *
     * @throws Throwable When the configured timezone identifier is invalid.
     */
    public static function getDefaultTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::SLOT_TIMEZONE);
    }

    /**
     * Detect Postgres EXCLUDE constraint errors for overlapping reservations.
     *
     * @throws Throwable When the driver exception payload is not accessible.
     */
    private function isOverlapConstraintViolation(QueryException $e): bool
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
     * @throws Throwable When Laravel event dispatching fails.
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
