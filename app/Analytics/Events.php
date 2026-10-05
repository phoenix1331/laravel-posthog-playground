<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * The analytics event catalogue: every event name the app sends, in one
 * place. Names are snake_case and past tense, describing something that
 * already happened.
 */
final class Events
{
    /** Captured by posthog-js on the client, listed here for completeness */
    public const string PAGEVIEW = '$pageview';

    public const string FIXTURE_VIEWED = 'fixture_viewed';

    public const string SEAT_SELECTED = 'seat_selected';

    public const string TRAVEL_ADDED = 'travel_added';

    public const string CHECKOUT_STARTED = 'checkout_started';

    public const string TICKET_PURCHASED = 'ticket_purchased';

    public const string CHECKOUT_ABANDONED = 'checkout_abandoned';

    /** PostHog's own exposure event, sent when a flag is first evaluated for a person */
    public const string FEATURE_FLAG_CALLED = '$feature_flag_called';

    /**
     * Server-side events in funnel order.
     *
     * @return list<string>
     */
    public static function funnel(): array
    {
        return [
            self::FIXTURE_VIEWED,
            self::SEAT_SELECTED,
            self::TRAVEL_ADDED,
            self::CHECKOUT_STARTED,
            self::TICKET_PURCHASED,
        ];
    }

    /**
     * Every event the server sends.
     *
     * @return list<string>
     */
    public static function server(): array
    {
        return [...self::funnel(), self::CHECKOUT_ABANDONED];
    }
}
