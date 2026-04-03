<?php

namespace App\ValueObjects;

/**
 * Represents slot availability for a specific parking spot.
 */
final readonly class SpotSlotAvailability
{
    /**
     * @param array<int, SlotAvailability> $slots
     */
    public function __construct(
        public int $id,
        public string $spotNumber,
        public array $slots,
    ) {
    }
}
