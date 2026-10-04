<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\AnalyticsDeliveryFailed;
use App\Analytics\PostHogAnalytics;
use App\Jobs\CaptureAnalyticsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

function postHogClient(RecordingHttpClient $http): Client
{
    return new Client('phc_test', ['host' => 'test.posthog.invalid'], $http, loadFeatureFlags: false);
}

describe('PostHogAnalytics', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Carbon::setTestNow('2026-10-04 15:00:00.123');
    });

    it('is bound when a token is set and sending is on', function (): void {
        config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
        app()->forgetInstance(Analytics::class);

        expect(app(Analytics::class))->toBeInstanceOf(PostHogAnalytics::class);
    });

    it('queues capture on the analytics queue instead of sending in the request', function (): void {
        (new PostHogAnalytics)->capture('user-1', 'seat_selected', ['quantity' => 2], ['supporters_club' => 'exiles']);

        Queue::assertPushedOn('analytics', CaptureAnalyticsEvent::class, function (CaptureAnalyticsEvent $job): bool {
            return $job->call === CaptureAnalyticsEvent::CAPTURE
                && $job->message['distinct_id'] === 'user-1'
                && $job->message['event'] === 'seat_selected'
                && $job->message['properties'] === ['quantity' => 2]
                && $job->message['groups'] === ['supporters_club' => 'exiles']
                && Str::isUuid($job->message['uuid']);
        });
    });

    it('stamps the event time at dispatch, not when the worker runs', function (): void {
        (new PostHogAnalytics)->capture('user-1', 'fixture_viewed');

        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->message['timestamp'] === '2026-10-04T15:00:00.123+00:00');
    });

    it('keeps a backdated timestamp from the simulator', function (): void {
        (new PostHogAnalytics)->capture('user-1', 'fixture_viewed', timestamp: Carbon::parse('2026-09-20 10:30:00'));

        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->message['timestamp'] === '2026-09-20T10:30:00.000+00:00');
    });

    it('omits groups when there are none', function (): void {
        (new PostHogAnalytics)->capture('user-1', 'fixture_viewed');

        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => ! array_key_exists('groups', $job->message));
    });

    it('queues identify and alias calls', function (): void {
        $analytics = new PostHogAnalytics;
        $analytics->identify('user-1', ['supporters_club' => 'exiles']);
        $analytics->alias('user-1', 'anon-1');

        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->call === CaptureAnalyticsEvent::IDENTIFY
            && $job->message['properties'] === ['supporters_club' => 'exiles']);
        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->call === CaptureAnalyticsEvent::ALIAS
            && $job->message['alias'] === 'anon-1');
    });

    it('sends group identify as a $groupidentify event', function (): void {
        (new PostHogAnalytics)->groupIdentify('supporters_club', 'exiles', ['club' => 'harbour-city']);

        Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->message['event'] === '$groupidentify'
            && $job->message['distinct_id'] === '$supporters_club_exiles'
            && $job->message['properties'] === [
                '$group_type' => 'supporters_club',
                '$group_key' => 'exiles',
                '$group_set' => ['club' => 'harbour-city'],
            ]);
    });
});

describe('CaptureAnalyticsEvent', function (): void {
    it('delivers the queued message through the real sdk with its uuid, time and groups', function (): void {
        Queue::fake();
        (new PostHogAnalytics)->capture('user-1', 'ticket_purchased', ['revenue' => 75.0], ['supporters_club' => 'exiles'], Carbon::parse('2026-09-20 10:30:00'));
        $job = Queue::pushed(CaptureAnalyticsEvent::class)->sole();
        $http = new RecordingHttpClient;

        $job->handle(postHogClient($http));

        $sent = $http->batchedMessages();
        expect($sent)->toHaveCount(1)
            ->and($sent[0])->toMatchArray([
                'event' => 'ticket_purchased',
                'distinct_id' => 'user-1',
                'uuid' => $job->message['uuid'],
            ])
            ->and($sent[0]['timestamp'])->toStartWith('2026-09-20T10:30:00')
            ->and($sent[0]['properties']['revenue'])->toBe(75)
            ->and($sent[0]['properties']['$groups'])->toBe(['supporters_club' => 'exiles']);
    });

    it('delivers identify and alias as $identify and $create_alias', function (): void {
        Queue::fake();
        $analytics = new PostHogAnalytics;
        $analytics->identify('user-1', ['supporters_club' => 'exiles']);
        $analytics->alias('user-1', 'anon-1');
        $http = new RecordingHttpClient;
        $client = postHogClient($http);

        Queue::pushed(CaptureAnalyticsEvent::class)->each(fn (CaptureAnalyticsEvent $job) => $job->handle($client));

        expect(collect($http->batchedMessages())->pluck('event')->all())->toBe(['$identify', '$create_alias'])
            ->and($http->batchedMessages()[0]['$set'])->toBe(['supporters_club' => 'exiles']);
    });

    it('flushes every job so a long-running worker never holds events', function (): void {
        $http = new RecordingHttpClient;

        (new CaptureAnalyticsEvent(CaptureAnalyticsEvent::CAPTURE, ['distinct_id' => 'user-1', 'event' => 'fixture_viewed']))
            ->handle(postHogClient($http));

        expect($http->requests)->toHaveCount(1);
    });

    it('fails the job so the queue retries when PostHog rejects the batch', function (): void {
        $job = new CaptureAnalyticsEvent(CaptureAnalyticsEvent::CAPTURE, ['distinct_id' => 'user-1', 'event' => 'fixture_viewed']);

        $job->handle(postHogClient(new RecordingHttpClient(status: 400)));
    })->throws(AnalyticsDeliveryFailed::class);

    it('retries with backoff before giving up', function (): void {
        $job = new CaptureAnalyticsEvent(CaptureAnalyticsEvent::CAPTURE, []);

        expect($job->tries)->toBe(5)->and($job->backoff())->toBe([5, 30, 120, 600]);
    });
});
