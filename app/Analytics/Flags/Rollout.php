<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

/**
 * PostHog's percentage rollout and variant assignment, reproduced so
 * fallback flags put the same visitors in the same bucket PostHog would.
 *
 * Both hash "<flag key>.<distinct ID><salt>" with SHA-1 and scale the first
 * 15 hex digits to 0-1. Rollout uses no salt and includes a visitor at or
 * below N/100; variants use the salt "variant" and pick the variant whose
 * cumulative range contains the value.
 */
final class Rollout
{
    private const int LONG_SCALE = 0xFFFFFFFFFFFFFFF;

    private const string VARIANT_SALT = 'variant';

    public static function includes(string $flagKey, string $distinctId, int $percentage): bool
    {
        return self::bucket($flagKey, $distinctId) <= $percentage / 100;
    }

    /**
     * @param  array<string, int>  $variants  variant key => rollout percentage, summing to 100
     */
    public static function variant(string $flagKey, string $distinctId, array $variants): ?string
    {
        $value = self::bucket($flagKey, $distinctId, self::VARIANT_SALT);
        $min = 0.0;

        foreach ($variants as $key => $percentage) {
            $max = $min + $percentage / 100;

            if ($value >= $min && $value < $max) {
                return $key;
            }

            $min = $max;
        }

        return null;
    }

    /**
     * Where a visitor falls between 0 and 1 for a flag.
     */
    public static function bucket(string $flagKey, string $distinctId, string $salt = ''): float
    {
        $prefix = substr(hash('sha1', "{$flagKey}.{$distinctId}{$salt}"), 0, 15);

        return hexdec($prefix) / self::LONG_SCALE;
    }
}
