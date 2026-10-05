<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Features\CheckoutCta;

/**
 * Feature flags running as PostHog experiments. The analytics subscriber
 * tags each experiment's listed events with its variant.
 */
final class Experiments
{
    /** @var list<class-string<Experiment>> */
    public const array ALL = [
        CheckoutCta::class,
    ];

    /**
     * Experiments whose variant belongs on this event.
     *
     * @return list<class-string<Experiment>>
     */
    public static function tagging(string $event): array
    {
        return array_values(array_filter(
            self::ALL,
            fn (string $experiment): bool => in_array($event, $experiment::taggedEvents(), true),
        ));
    }
}
