<?php

namespace App\Services;

use App\Events\ParkingSlotStatusChanged;
use App\ValueObjects\SpotSlotAvailability;

class ParkingSlotsRealtimeService
{
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
