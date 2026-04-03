<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job that periodically marks expired booked reservations as completed.
 *
 * No need to trigger UI events here: these reservations already ended,
 * so the corresponding slots are no longer available in any active time window.
 */
class ReleaseStaleReservationsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    private const BATCH_SIZE = 100;

    public function __construct(private ?ReservationService $reservationService = null)
    {
        $this->reservationService ??= app(ReservationService::class);
    }

    public function handle(): void
    {
        $batchSize = self::BATCH_SIZE;
        do {
            // This is using lockForUpdate to prevent other processes from modifying the same rows.
            // Right now the system is only enableing one job at a time, but in the future it will be possible to run multiple jobs in parallel.

            /** @var Collection<int, Reservation> $completedReservations */
            $completedReservations = $this->reservationService->completeStaleReservationsBatch(now('UTC'), $batchSize);

            foreach ($completedReservations as $reservation) {
                Log::channel('stale_reservations')->info(sprintf(
                    'Auto-released Spot #%d (Reservation ID %d)',
                    $reservation->spot_id,
                    $reservation->id,
                ));
            }

            // Will finish when there is less than $batchSize rows returned which means it's the last iteration.
        } while ($completedReservations->count() === $batchSize);
    }
}
