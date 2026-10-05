<?php

declare(strict_types=1);

namespace App\Analytics;

use Carbon\CarbonInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Used when there is no PostHog token or analytics is switched off. Drops
 * every call, or writes it to a log when a logger is given so events can
 * be watched locally without an account.
 */
final class NullAnalytics implements Analytics
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        ?CarbonInterface $timestamp = null,
    ): void {
        $this->log('capture', [
            'distinct_id' => $distinctId,
            'event' => $event,
            'properties' => $properties,
            'groups' => $groups,
            'timestamp' => $timestamp?->toIso8601String(),
        ]);
    }

    public function identify(string $distinctId, array $properties = []): void
    {
        $this->log('identify', ['distinct_id' => $distinctId, 'properties' => $properties]);
    }

    public function alias(string $distinctId, string $alias): void
    {
        $this->log('alias', ['distinct_id' => $distinctId, 'alias' => $alias]);
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->log('groupIdentify', ['group_type' => $groupType, 'group_key' => $groupKey, 'properties' => $properties]);
    }

    public function captureException(string $distinctId, Throwable $exception, array $properties = []): void
    {
        $this->log('captureException', [
            'distinct_id' => $distinctId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'properties' => $properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $call, array $context): void
    {
        $this->logger?->debug("analytics.{$call}", $context);
    }
}
