<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ParkingSlotStatusChanged implements ShouldBroadcastNow
{
    /**
     * Broadcast a point update for a single spot+slot so clients update one cell.
     */
    public function __construct(
        public readonly string $date,
        public readonly int $spotId,
        public readonly string $slotKey,
        public readonly string $start,
        public readonly string $end,
        public readonly string $startUtc,
        public readonly string $endUtc,
        public readonly bool $taken,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('parking.slots.'.$this->date);
    }

    public function broadcastAs(): string
    {
        return 'parking.slots.slot-updated';
    }

    /**
     * Provide a payload keyed by slot key so the frontend can patch state predictably.
     */
    public function broadcastWith(): array
    {
        return [
            'date' => $this->date,
            'spotId' => $this->spotId,
            'slot' => [
                'key' => $this->slotKey,
                'start' => $this->start,
                'end' => $this->end,
                'startUtc' => $this->startUtc,
                'endUtc' => $this->endUtc,
                'taken' => $this->taken,
            ],
        ];
    }
}
