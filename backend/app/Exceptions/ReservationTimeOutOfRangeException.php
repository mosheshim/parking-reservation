<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Indicates the requested reservation range is outside the allowed daily booking window (avilable slots times).
 */
class ReservationTimeOutOfRangeException extends RuntimeException
{
}
