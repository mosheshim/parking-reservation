<?php

namespace App\ValueObjects;

/**
 * Represents a fixed daily time slot and whether it is taken.
 */
final readonly class SlotAvailability
{
    public function __construct(
        public string $start,
        public string $end,
        public bool $taken,
    ) {
    }
}
