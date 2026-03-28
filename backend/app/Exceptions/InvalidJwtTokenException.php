<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidJwtTokenException extends RuntimeException
{
    /**
     * Create an exception representing an invalid, expired, or unverifiable JWT.
     *
     * This exists to avoid leaking JWT vendor exception types outside the AuthService boundary.
     */
    public function __construct(string $message = 'Invalid or expired token', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
