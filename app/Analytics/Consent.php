<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * The visitor's analytics choice, stored by the consent banner in a plain
 * first-party cookie both the browser and the server can read.
 *
 * - granted: posthog-js captures, persists and records replays
 * - denied: nothing is captured for this visitor, client or server side
 * - unknown (no choice yet): posthog-js stays opted out with no cookies or
 *   replay; server-side first-party events still flow
 */
final class Consent
{
    public const string COOKIE = 'analytics_consent';

    public const string GRANTED = 'granted';

    public const string DENIED = 'denied';

    public const string UNKNOWN = 'unknown';

    public static function state(): string
    {
        $value = request()->cookies->get(self::COOKIE);

        return in_array($value, [self::GRANTED, self::DENIED], true) ? $value : self::UNKNOWN;
    }

    public static function denied(): bool
    {
        return self::state() === self::DENIED;
    }
}
