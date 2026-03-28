<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReservationService
{
    /**
     * Create a reservation for a user.
     *
     * Concurrency is enforced by a Postgres EXCLUDE constraint (see migration).
     * If a conflicting reservation exists, Postgres rejects the insert; we map it to a user-friendly error.
     *
     * @throws RuntimeException When reservation cannot be created due to conflict.
     */
    public function create(User $user, int $spotId, string $startTime, string $endTime): Reservation
    {
        try {
            return DB::transaction(static function () use ($user, $spotId, $startTime, $endTime): Reservation {
                $reservation = new Reservation();
                $reservation->user_id = $user->id;
                $reservation->spot_id = $spotId;
                $reservation->start_time = $startTime;
                $reservation->end_time = $endTime;
                $reservation->status = 'Booked';
                $reservation->save();

                return $reservation;
            });
        } catch (QueryException $e) {
            if ($this->isOverlapConstraintViolation($e)) {
                throw new RuntimeException('This spot is already reserved for the selected time range. Please choose a different time.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Mark a reservation as completed.
     */
    public function complete(int $reservationId): Reservation
    {
        return DB::transaction(static function () use ($reservationId): Reservation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'Completed') {
                $reservation->status = 'Completed';
                $reservation->save();
            }

            return $reservation;
        });
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
