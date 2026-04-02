<?php

namespace App\Services;

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

    // These constants can be moved to the DB and set per parking lot in the future.
    public const SLOT_TIMEZONE = 'Asia/Jerusalem';
    public const SLOT_DEFINITIONS = [
        ['start' => '07:30', 'end' => '12:05'],
        ['start' => '12:05', 'end' => '17:34'],
        ['start' => '17:34', 'end' => '22:00'],
    ];

    public function __construct(
        private readonly ParkingSlotsRealtimeService $parkingSlotsRealtimeService,
    ) {
    }

    /**
     * Create a reservation for a user.
     *
     * Concurrency is enforced by a Postgres EXCLUDE constraint (see migration).
     * If a conflicting reservation exists, Postgres rejects the insert; we map it to a user-friendly error.
     *
     * @throws ReservationTimeConflictException When reservation overlaps an existing active reservation.
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

        // Sending "now" start time from the frontend may be in the past until the server processes it.
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
        $this->broadcastAvailabilityUpdateForReservation($reservation, $localDate, $timezone);
        return $reservation;
    }

    /**
     * Broadcast the minimal set of slot updates impacted by a reservation change.
     *
     * This exists so realtime updates do not require recomputing (and broadcasting) the full daily snapshot.
     * Only slots whose UTC ranges overlap the reservation window are queried and emitted.
     */
    protected function broadcastAvailabilityUpdateForReservation(Reservation $reservation, string $date, ?DateTimeZone $timezone = null): void
    {
        $spotAvailabilities = $this->getSpotSlotAvailabilityUpdatesForReservation($reservation, $date, $timezone);
        $this->parkingSlotsRealtimeService->broadcastSpotSlotAvailability($date, $spotAvailabilities);
    }

    /**
     * Compute slot availability updates for all spots, restricted to the slot(s) overlapped by the reservation range.
     *
     * Reservations are stored in UTC; slot boundaries are built from the provided local date/timezone and then
     * converted to UTC so comparisons and overlap queries happen on the same timeline as the database.
     *
     * @return array<int, SpotSlotAvailability>
     */
    public function getSpotSlotAvailabilityUpdatesForReservation(Reservation $reservation, string $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $localDate = Carbon::parse($date, $timezone)->startOfDay();
        $slotDefinitions = self::SLOT_DEFINITIONS;
        $slotRangesUtc = $this->buildSlotRangesUtc($localDate, $timezone, $slotDefinitions);

        $reservationStartUtc = $reservation->start_time->copy()->utc();
        $reservationEndUtc = $reservation->end_time->copy()->utc();

        $affectedSlotIndexes = $this->getOverlappingSlotIndexes($slotRangesUtc, $reservationStartUtc, $reservationEndUtc);
        if ($affectedSlotIndexes === []) {
            return [];
        }

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
     * @param array<int, array{start:Carbon, end:Carbon}> $slotRangesUtc
     * @return array<int, int>
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
     * reservations may only be created within the daily window 08:00-20:00 (local time).
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
     * Mark a reservation as completed. Will update the end time to "now".
     *
     * @throws ModelNotFoundException When the reservation does not exist.
     * @throws QueryException When the database rejects the update.
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
        $this->broadcastAvailabilityUpdateForReservation($reservation->refresh(), $localDate, $timezone);
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
     */
    public function getSlotAvailabilityForDate(Carbon $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= self::getDefaultTimezone();

        $slotDefinitions = self::SLOT_DEFINITIONS;
        $slotRangesUtc = $this->buildSlotRangesUtc($date, $timezone, $slotDefinitions);

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
     */
    private static function getDefaultTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::SLOT_TIMEZONE);
    }

    /**
     * Detect Postgres EXCLUDE constraint errors for overlapping reservations.
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
}
