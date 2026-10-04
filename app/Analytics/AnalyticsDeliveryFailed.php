<?php

declare(strict_types=1);

namespace App\Analytics;

use RuntimeException;

/**
 * PostHog did not accept a batch. Thrown from the queued job so the queue
 * retries it; the message UUID stops a successful retry double-counting.
 */
final class AnalyticsDeliveryFailed extends RuntimeException
{
    public static function for(string $call): self
    {
        return new self("PostHog did not accept the {$call} batch.");
    }
}
