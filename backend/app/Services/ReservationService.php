<?php

namespace App\Services;

use App\Exceptions\ReservationTimeConflictException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationService
{
    /**
     * Create a reservation for a user.
     *
     * Concurrency is enforced by a Postgres EXCLUDE constraint (see migration).
     * If a conflicting reservation exists, Postgres rejects the insert; we map it to a user-friendly error.
     *
     * @throws ReservationTimeConflictException When reservation overlaps an existing active reservation.
     * @throws QueryException When the database rejects the insert for any other reason.
     */
    public function create(User $user, int $spotId, string $startTime, string $endTime): Reservation
    {
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
