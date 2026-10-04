<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Jobs\CaptureAnalyticsEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

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
