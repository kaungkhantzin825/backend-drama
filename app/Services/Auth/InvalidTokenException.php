<?php

namespace App\Services\Auth;

use Exception;

/**
 * Thrown when a Google ID token cannot be verified.
 *
 * Kept as its own type so the controller can turn it into a 401 without
 * catching unrelated exceptions.
 */
class InvalidTokenException extends Exception
{
}
