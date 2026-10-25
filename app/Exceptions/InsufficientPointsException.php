<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a debit would push the balance below zero.
 *
 * Controllers turn this into a 422 with a readable message so the client can
 * show "not enough points" instead of a generic failure.
 */
class InsufficientPointsException extends RuntimeException
{
    public static function for(int $needed, int $available): self
    {
        return new self(sprintf(
            'Not enough points. This needs %d and your balance is %d.',
            $needed,
            $available,
        ));
    }
}
