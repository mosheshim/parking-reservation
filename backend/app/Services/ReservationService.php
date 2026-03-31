<?php

namespace App\Services;

use App\Exceptions\ReservationTimeConflictException;
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
    public const SLOT_TIMEZONE = 'Asia/Jerusalem';

    public const SLOT_DEFINITIONS = [
        ['start' => '08:00', 'end' => '12:00'],
        ['start' => '12:00', 'end' => '16:00'],
        ['start' => '16:00', 'end' => '20:00'],
    ];

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
    public function create(User $user, int $spotId, string $startTime, string $endTime): Reservation
    {
        // Postgres aborts the current transaction on constraint violations (e.g. overlap EXCLUDE).
        // Wrapping the insert in its own transaction creates a savepoint under an outer transaction (like RefreshDatabase),
        // allowing the failed insert to roll back cleanly without poisoning subsequent queries.
        return DB::transaction(function () use ($user, $spotId, $startTime, $endTime): Reservation {
            try {
                $reservation = new Reservation();
                $reservation->user_id = $user->id;
                $reservation->spot_id = $spotId;
                $reservation->start_time = $startTime;
                $reservation->end_time = $endTime;
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
    }

    /**
     * Mark a reservation as completed.
     *
     * @throws ModelNotFoundException When the reservation does not exist.
     * @throws QueryException When the database rejects the update.
     */
    public function complete(int $reservationId): void
    {
        Reservation::query()
            ->whereKey($reservationId)
            ->where('status', '!=', Reservation::STATUS_COMPLETED)
            ->update(['status' => Reservation::STATUS_COMPLETED]);
    }

    /**
     * Return, for a given local date/timezone pair, which of the fixed daily slots are taken per parking spot.
     *
     * Reservations are stored in UTC; slot boundaries are built in the requested timezone and then converted to UTC
     *  so the overlap query compares the same timeline the database stores.
     *
     * @param Carbon $date The input date is copied before any mutation so callers keep their original Carbon instance unchanged.
     * @param DateTimeZone $timezone
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

                $slots[] = new SlotAvailability(
                    start: $slotDefinition['start'],
                    end: $slotDefinition['end'],
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
