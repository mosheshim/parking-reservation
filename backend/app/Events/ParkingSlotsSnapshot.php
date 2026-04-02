<?php

namespace App\Events;

use App\ValueObjects\SpotSlotAvailability;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ParkingSlotsSnapshot implements ShouldBroadcastNow
{
    /**
     * Broadcast a full slots snapshot for a date on the date's private channel.
     * Used to bootstrap the UI purely via WebSocket.
     *
     * @param array<int, SpotSlotAvailability> $availability
     */
    public function __construct(
        public readonly string $date,
        public readonly array $availability,
    ) {
    }

    /**
     * Return the private channel clients subscribe to for the selected date.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('parking.slots.'.$this->date);
    }

    public function broadcastAs(): string
    {
        return 'parking.slots.snapshot';
    }

    /**
     * Provide a stable payload shape for the frontend slots board.
     */
    public function broadcastWith(): array
    {
        return [
            'date' => $this->date,
            'spots' => $this->availability,
        ];
    }
}
