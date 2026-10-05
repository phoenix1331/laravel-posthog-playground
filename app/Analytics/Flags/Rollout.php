<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

/**
 * PostHog's percentage rollout, reproduced so fallback flags put the same
 * visitors in the same bucket PostHog would. A visitor is in a rollout of
 * N% when the hash of "<flag key>.<distinct ID>" scales to N/100 or less;
 * the hash is the first 15 hex digits of its SHA-1.
 */
final class Rollout
{
    private const int LONG_SCALE = 0xFFFFFFFFFFFFFFF;

    public static function includes(string $flagKey, string $distinctId, int $percentage): bool
    {
        return self::bucket($flagKey, $distinctId) <= $percentage / 100;
    }

    /**
     * Where a visitor falls between 0 and 1 for a flag.
     */
    public static function bucket(string $flagKey, string $distinctId): float
    {
        $prefix = substr(hash('sha1', "{$flagKey}.{$distinctId}"), 0, 15);

        return hexdec($prefix) / self::LONG_SCALE;
    }
}
