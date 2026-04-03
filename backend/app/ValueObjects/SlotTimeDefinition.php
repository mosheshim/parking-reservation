<?php

namespace App\ValueObjects;

/**
 * Represents a single fixed slot boundary in local time (e.g. 08:00-12:00).
 * Required so callers don't couple to raw array definitions.
 */
final readonly class SlotTimeDefinition
{
    public function __construct(
        public string $start,
        public string $end,
    ) {
    }
}
