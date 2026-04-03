<?php

namespace App\Services;

use App\Events\ParkingSlotStatusChanged;
use App\ValueObjects\SlotDefinition;
use App\ValueObjects\SlotTimeDefinition;
use App\ValueObjects\SpotSlotAvailability;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeZone;
use Illuminate\Support\Carbon;

class SlotService
{
    private const SLOT_TIMEZONE = 'Asia/Jerusalem';

    /**
     * @var array<int, array{start:string, end:string}>
     */
    private const SLOT_TIME_DEFINITIONS = [
        ['start' => '08:00', 'end' => '12:00'],
        ['start' => '12:00', 'end' => '16:00'],
        ['start' => '16:00', 'end' => '22:00'],
    ];

    /**
     * Return the configured timezone identifier used to interpret slot boundaries.
     */
    public function getSlotTimezone(): string
    {
        return self::SLOT_TIMEZONE;
    }

    /**
     * Build the default timezone object used for interpreting slot boundaries.
     * A factory method is required because PHP constants cannot hold objects.
     */
    public function getDefaultTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::SLOT_TIMEZONE);
    }

    /**
     * Return the raw slot time definitions (local-time boundaries only).
     * This exists so consumers can derive allowed windows without coupling to internal constants.
     *
     * @return array<int, SlotTimeDefinition>
     */
    public function getSlotTimeDefinitions(): array
    {
        $definitions = [];
        foreach (self::SLOT_TIME_DEFINITIONS as $definition) {
            $definitions[] = new SlotTimeDefinition(
                start: $definition['start'],
                end: $definition['end'],
            );
        }

        return $definitions;
    }

    /**
     * Build full slot definitions for a given date/timezone, including precomputed UTC boundaries.
     * Required so all overlap checks and UI payloads use the same UTC conversions.
     *
     * @return array<int, SlotDefinition>
     * @throws InvalidFormatException
     */
    public function getSlotDefinitionsForDate(Carbon $date, ?DateTimeZone $timezone = null): array
    {
        $timezone ??= $this->getDefaultTimezone();

        $localDate = $date->copy()->setTimezone($timezone)->startOfDay();

        $definitions = [];
        foreach ($this->getSlotTimeDefinitions() as $slotTime) {
            $localStart = $localDate->copy()->setTimeFromTimeString($slotTime->start);
            $localEnd = $localDate->copy()->setTimeFromTimeString($slotTime->end);

            $startUtc = $localStart->copy()->utc();
            $endUtc = $localEnd->copy()->utc();

            $definitions[] = new SlotDefinition(
                key: $this->buildSlotKey($slotTime->start, $slotTime->end),
                start: $slotTime->start,
                end: $slotTime->end,
                startUtc: $startUtc,
                endUtc: $endUtc,
            );
        }

        return $definitions;
    }

    /**
     * Identify which slots overlap a given UTC time window.
     * This exists so callers can limit queries/broadcasts to only impacted slot cells.
     *
     * @param array<int, SlotDefinition> $slotDefinitions
     * @return array<int, int>
     */
    public function getOverlappingSlotIndexes(array $slotDefinitions, Carbon $startUtc, Carbon $endUtc): array
    {
        $affected = [];
        foreach ($slotDefinitions as $slotIndex => $slot) {
            // Overlap check: [a,b) intersects [c,d) iff a < d and b > c.
            if ($startUtc->lt($slot->endUtc) && $endUtc->gt($slot->startUtc)) {
                $affected[] = $slotIndex;
            }
        }

        return $affected;
    }

    /**
     * Broadcast a set of spot slot updates to listeners of the given local date.
     * This exists so the slot-related event shape stays centralized with other slot logic.
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

    /**
     * Build a stable identifier for a slot based on its local-time boundaries.
     * This exists so the frontend can update a specific slot when receiving real-time events.
     */
    protected function buildSlotKey(string $startLocalTime, string $endLocalTime): string
    {
        return $startLocalTime.' - '.$endLocalTime;
    }
}
