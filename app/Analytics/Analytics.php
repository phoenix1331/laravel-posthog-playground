<?php

declare(strict_types=1);

namespace App\Analytics;

use Carbon\CarbonInterface;
use Throwable;

/**
 * The only way application code talks to an analytics backend. Listeners
 * call this; controllers and models never do. Implementations decide
 * whether a call goes to PostHog, a log, or nowhere.
 */
interface Analytics
{
    /**
     * Record that something happened.
     *
     * @param  string  $distinctId  the person: user ID once known, otherwise the anonymous ID
     * @param  string  $event  a name from App\Analytics\Events
     * @param  array<string, scalar>  $properties  built with App\Analytics\EventProperties
     * @param  array<string, string>  $groups  group type => group key, e.g. ['supporters_club' => 'harbour-city-exiles']
     * @param  CarbonInterface|null  $timestamp  when it happened; null means now
     */
    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        ?CarbonInterface $timestamp = null,
    ): void;

    /**
     * Set properties on a person.
     *
     * @param  array<string, scalar>  $properties
     */
    public function identify(string $distinctId, array $properties = []): void;

    /**
     * Link two distinct IDs so their events count as one person, e.g. the
     * anonymous browser ID and the user ID after login.
     */
    public function alias(string $distinctId, string $alias): void;

    /**
     * Set properties on a group, such as a supporters' club.
     *
     * @param  array<string, scalar>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void;

    /**
     * Report an exception for PostHog error tracking.
     *
     * @param  array<string, scalar|null>  $properties  context such as $session_id and the route
     */
    public function captureException(string $distinctId, Throwable $exception, array $properties = []): void;
}
