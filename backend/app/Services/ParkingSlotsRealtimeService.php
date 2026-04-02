<?php

namespace App\Services;

use App\Events\ParkingSlotStatusChanged;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Support\Carbon;

class ParkingSlotsRealtimeService
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * Broadcast the current taken/available status for all slots of a spot on a date.
     */
    public function broadcastSpotSlots(string $date, int $spotId): void
    {
        $availability = $this->reservationService->getSlotAvailabilityForDate(
            Carbon::parse($date, ReservationService::SLOT_TIMEZONE),
            new DateTimeZone(ReservationService::SLOT_TIMEZONE),
        );

        $spotAvailability = collect($availability)->firstWhere('id', $spotId);
        if (!$spotAvailability instanceof SpotSlotAvailability) {
            return;
        }

        foreach ($spotAvailability->slots as $slot) {
            event(new ParkingSlotStatusChanged(
                date: $date,
                spotId: $spotId,
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
