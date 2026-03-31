<?php

namespace App\Services;

use App\Exceptions\ReservationTimeConflictException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
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
     * todo use Date object instead of date string
     * Return, for a given Jerusalem-local date, which of the fixed daily slots are taken per parking spot.
     *
     * Reservations are stored in UTC; slot boundaries are built in Asia/Jerusalem and then converted to UTC
     * to correctly account for DST changes.
     *
     * @return array<int, array{id:int, spot_number:mixed, slots:array<int, array{start:string, end:string, taken:bool}>}>
     */
    public function getSlotAvailabilityForDate(string $date): array
    {
        $slotDefinitions = self::SLOT_DEFINITIONS;
        $slotRangesUtc = $this->buildSlotRangesUtc($date, $slotDefinitions);

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

        foreach ($spots as $spot) {
            $slots = [];
            foreach ($slotDefinitions as $index => $slotDefinition) {
                $isTaken = isset($takenSpotIdsBySlot[$index][$spot->id]);

                $slots[] = [
                    'start' => $slotDefinition['start'],
                    'end' => $slotDefinition['end'],
                    'taken' => $isTaken,
                ];
            }

            $result[] = [
                'id' => $spot->id,
                'spot_number' => $spot->spot_number,
                'slots' => $slots,
            ];
        }

        return $result;
    }

    /**
     * Convert fixed local-time slot definitions into UTC ranges for a specific date.
     * Required so the database overlap query uses the same UTC timeline as stored reservations.
     *
     * @param array<int, array{start:string, end:string}> $slotDefinitions
     * @return array<int, array{start:Carbon, end:Carbon}>
     */
    private function buildSlotRangesUtc(string $date, array $slotDefinitions): array
    {
        $localDate = Carbon::parse($date, self::SLOT_TIMEZONE)->startOfDay();

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
