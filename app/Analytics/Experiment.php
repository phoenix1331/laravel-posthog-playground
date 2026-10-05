<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * A feature flag that is a PostHog experiment. Its variant is added to the
 * listed analytics events as "$feature/<key>", which is how PostHog
 * attributes server-side events to experiment variants.
 *
 * Lives outside app/Features because Feature::discover() would try to
 * instantiate an interface found there.
 */
interface Experiment
{
    /**
     * The PostHog flag key.
     */
    public static function key(): string;

    /**
     * Analytics events to tag with the variant. Keep these to events after
     * the variant is shown, so tagging never records exposure early.
     *
     * @return list<string>
     */
    public static function taggedEvents(): array;
}
