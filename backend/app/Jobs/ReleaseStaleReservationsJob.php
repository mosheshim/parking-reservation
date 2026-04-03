<?php

namespace App\Jobs;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Background job that periodically marks expired booked reservations as completed.
 *
 * No need to trigger UI events here: these reservations already ended,
 * so the corresponding slots are no longer available in any active time window.
 */
class ReleaseStaleReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE = 100;

    public function handle(): void
    {
        $batchSize = self::BATCH_SIZE;
        $now = now('UTC');
        do {
            /** @var Collection<int, Reservation> $completedReservations */
            $completedReservations = DB::transaction(function () use ($batchSize, $now): Collection {
                $rows = Reservation::query()
                    ->where('status', Reservation::STATUS_BOOKED)
                    ->where('end_time', '<', $now)
                    ->lockForUpdate()
                    ->limit($batchSize)
                    ->get(['id', 'spot_id']);

                if ($rows->isEmpty()) {
                    return $rows;
                }

                $ids = $rows->pluck('id');

                Reservation::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => Reservation::STATUS_COMPLETED,
                        'completed_at' => now('UTC'),
                    ]);

                return $rows;
            });


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
