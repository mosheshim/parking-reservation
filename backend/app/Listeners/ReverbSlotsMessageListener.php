<?php

namespace App\Listeners;

use App\Services\ParkingSlotsRealtimeService;
use Illuminate\Support\Arr;
use Laravel\Reverb\Events\MessageReceived;

class ReverbSlotsMessageListener
{
    private const SNAPSHOT_REQUEST_EVENT = 'client-parking.slots.snapshot-request';

    public function __construct(
        private readonly ParkingSlotsRealtimeService $realtimeService,
    ) {
    }

    /**
     * Handle a client message from Reverb and broadcast a snapshot when requested.
     */
    public function handle(MessageReceived $event): void
    {
        $payload = json_decode((string) $event->message, true);
        if (!is_array($payload)) {
            return;
        }

        $eventName = Arr::get($payload, 'event');
        if (!is_string($eventName) || $eventName !== self::SNAPSHOT_REQUEST_EVENT) {
            return;
        }

        $rawData = Arr::get($payload, 'data');
        $data = $rawData;
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (!is_array($data)) {
            return;
        }

        $date = $data['date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }

        $this->realtimeService->broadcastSnapshot($date);
    }
}
