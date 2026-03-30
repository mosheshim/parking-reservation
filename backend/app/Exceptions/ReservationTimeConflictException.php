<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ReservationTimeConflictException extends RuntimeException
{
    /**
     * Create an exception indicating an attempted reservation overlaps an existing active reservation.
     */
    public function __construct(string $message = 'This spot is already reserved for the selected time range. Please choose a different time.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
