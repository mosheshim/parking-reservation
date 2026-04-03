<?php

namespace App\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * Represents a fixed slot for a specific date/timezone with precomputed UTC boundaries.
 * Required so slot calculations and UTC conversions are not duplicated across services and tests.
 */
final readonly class SlotDefinition
{
    public function __construct(
        public string $key,
        public string $start,
        public string $end,
        public Carbon $startUtc,
        public Carbon $endUtc,
    ) {
    }
}
