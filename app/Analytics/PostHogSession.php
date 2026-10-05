<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Http\Request;

/**
 * The posthog-js session (and so the session replay) a request belongs to.
 *
 * fetch/XHR requests carry it in the X-POSTHOG-SESSION-ID header (the
 * tracing_headers option); page loads only have the posthog-js cookie, whose
 * "$sesid" entry is [last activity, session ID, session start].
 */
final class PostHogSession
{
    public const string HEADER = 'X-POSTHOG-SESSION-ID';

    private const int MAX_LENGTH = 200;

    public static function id(Request $request): ?string
    {
        $id = $request->header(self::HEADER) ?? self::fromCookie($request);

        return is_string($id) && $id !== '' && strlen($id) <= self::MAX_LENGTH ? $id : null;
    }

    private static function fromCookie(Request $request): ?string
    {
        $name = DistinctId::postHogCookieName();

        if ($name === null) {
            return null;
        }

        $state = json_decode((string) $request->cookies->get($name), true);
        $session = is_array($state) ? ($state['$sesid'] ?? null) : null;

        return is_array($session) && is_string($session[1] ?? null) ? $session[1] : null;
    }
}
