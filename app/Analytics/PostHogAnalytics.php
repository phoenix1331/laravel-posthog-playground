<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Jobs\CaptureAnalyticsEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use PostHog\ExceptionPayloadBuilder;
use Throwable;

/**
 * Sends analytics to PostHog without touching the network in the request.
 *
 * Each call is turned into a PostHog message here, stamped with its time and
 * a UUID, then queued. The time is fixed at dispatch so a queue backlog does
 * not shift events later; the UUID is fixed so a retried job is
 * de-duplicated by PostHog instead of counted twice.
 */
final class PostHogAnalytics implements Analytics
{
    private const int MAX_FRAMES = 30;

    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        ?CarbonInterface $timestamp = null,
    ): void {
        $message = [
            'uuid' => (string) Str::uuid7(),
            'distinct_id' => $distinctId,
            'event' => $event,
            'properties' => $properties,
            'timestamp' => $this->format($timestamp),
        ];

        if ($groups !== []) {
            $message['groups'] = $groups;
        }

        $this->queue(CaptureAnalyticsEvent::CAPTURE, $message);
    }

    public function identify(string $distinctId, array $properties = []): void
    {
        $this->queue(CaptureAnalyticsEvent::IDENTIFY, [
            'distinct_id' => $distinctId,
            'properties' => $properties,
            'timestamp' => $this->format(null),
        ]);
    }

    public function alias(string $distinctId, string $alias): void
    {
        $this->queue(CaptureAnalyticsEvent::ALIAS, [
            'distinct_id' => $distinctId,
            'alias' => $alias,
            'timestamp' => $this->format(null),
        ]);
    }

    /**
     * The PHP client has no groupIdentify(); like the SDK's static facade,
     * send a $groupidentify event against a synthetic distinct ID.
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->queue(CaptureAnalyticsEvent::CAPTURE, [
            'uuid' => (string) Str::uuid7(),
            'distinct_id' => "\${$groupType}_{$groupKey}",
            'event' => '$groupidentify',
            'properties' => [
                '$group_type' => $groupType,
                '$group_key' => $groupKey,
                '$group_set' => $properties,
            ],
            'timestamp' => $this->format(null),
        ]);
    }

    /**
     * Error tracking's $exception event. The stack trace is serialised here,
     * in the request, with the SDK's own builder: a Throwable cannot be
     * queued, and the trace must describe this process anyway. Reaching this
     * point means the exception got past the app to Laravel's handler, so it
     * is marked unhandled.
     */
    public function captureException(string $distinctId, Throwable $exception, array $properties = []): void
    {
        $exceptionList = ExceptionPayloadBuilder::overridePrimaryMechanism(
            ExceptionPayloadBuilder::buildExceptionList($exception, self::MAX_FRAMES),
            ['type' => 'laravel', 'handled' => false],
        );

        $this->queue(CaptureAnalyticsEvent::CAPTURE, [
            'uuid' => (string) Str::uuid7(),
            'distinct_id' => $distinctId,
            'event' => '$exception',
            'properties' => [
                ...$properties,
                '$exception_list' => $exceptionList,
                '$exception_handled' => false,
            ],
            'timestamp' => $this->format(null),
        ]);
    }

    /**
     * @param  CaptureAnalyticsEvent::*  $call
     * @param  array<string, mixed>  $message
     */
    private function queue(string $call, array $message): void
    {
        CaptureAnalyticsEvent::dispatch($call, $message)->onQueue(config('posthog.queue'));
    }

    private function format(?CarbonInterface $timestamp): string
    {
        return ($timestamp ?? now())->toImmutable()->utc()->format('Y-m-d\TH:i:s.vP');
    }
}
