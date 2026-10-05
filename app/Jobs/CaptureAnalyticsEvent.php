<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Analytics\AnalyticsDeliveryFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PostHog\Client;
use Throwable;

/**
 * Delivers one prepared PostHog message from the queue worker.
 *
 * The client batches in memory and only sends on flush(), so every job
 * flushes before finishing; otherwise a long-running worker would hold
 * events until the batch filled up.
 */
class CaptureAnalyticsEvent implements ShouldQueue
{
    use Queueable;

    public const string CAPTURE = 'capture';

    public const string IDENTIFY = 'identify';

    public const string ALIAS = 'alias';

    public int $tries = 5;

    /**
     * @param  self::CAPTURE|self::IDENTIFY|self::ALIAS  $call
     * @param  array<string, mixed>  $message  prepared by App\Analytics\PostHogAnalytics
     */
    public function __construct(
        public readonly string $call,
        public readonly array $message,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    /**
     * Anything that goes wrong surfaces as AnalyticsDeliveryFailed, which
     * ErrorReporter never sends to PostHog: reporting a failed delivery as
     * an error would queue another delivery, and loop while PostHog is down.
     */
    public function handle(Client $posthog): void
    {
        try {
            $posthog->{$this->call}($this->message);
            $delivered = $posthog->flush();
        } catch (Throwable $exception) {
            throw AnalyticsDeliveryFailed::for($this->call, $exception);
        }

        if (! $delivered) {
            throw AnalyticsDeliveryFailed::for($this->call);
        }
    }
}
