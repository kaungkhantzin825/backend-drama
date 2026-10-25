<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a daily earning or payout cap has been reached.
 */
class RewardLimitException extends RuntimeException
{
    public static function dailyAds(int $limit): self
    {
        return new self(sprintf('You have reached the daily limit of %d rewarded ads. Come back tomorrow.', $limit));
    }

    public static function alreadyCheckedIn(): self
    {
        return new self('You have already checked in today.');
    }

    public static function dailyWithdrawals(int $limit): self
    {
        return new self(sprintf('You can request %d withdrawal per day.', $limit));
    }

    public static function belowMinimum(int $minimum): self
    {
        return new self(sprintf('The minimum withdrawal is %d points.', $minimum));
    }
}
