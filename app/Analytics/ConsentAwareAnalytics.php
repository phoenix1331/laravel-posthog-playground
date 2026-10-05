<?php

declare(strict_types=1);

namespace App\Analytics;

use Carbon\CarbonInterface;

/**
 * Wraps the real Analytics driver and drops every call for a visitor who
 * declined analytics. The choice is read from the current request each
 * time, so a single bound instance serves every visitor; outside a request
 * (queue jobs, the simulator) there is no choice to honour and calls pass
 * through.
 */
final class ConsentAwareAnalytics implements Analytics
{
    public function __construct(private readonly Analytics $inner) {}

    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        ?CarbonInterface $timestamp = null,
    ): void {
        if (Consent::denied()) {
            return;
        }

        $this->inner->capture($distinctId, $event, $properties, $groups, $timestamp);
    }

    public function identify(string $distinctId, array $properties = []): void
    {
        if (Consent::denied()) {
            return;
        }

        $this->inner->identify($distinctId, $properties);
    }

    public function alias(string $distinctId, string $alias): void
    {
        if (Consent::denied()) {
            return;
        }

        $this->inner->alias($distinctId, $alias);
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (Consent::denied()) {
            return;
        }

        $this->inner->groupIdentify($groupType, $groupKey, $properties);
    }

    public function inner(): Analytics
    {
        return $this->inner;
    }
}
