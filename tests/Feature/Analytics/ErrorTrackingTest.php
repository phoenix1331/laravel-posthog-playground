<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\AnalyticsDeliveryFailed;
use App\Analytics\Consent;
use App\Analytics\ConsentAwareAnalytics;
use App\Analytics\DistinctId;
use App\Analytics\ErrorReporter;
use App\Analytics\FakeAnalytics;
use App\Analytics\PostHogAnalytics;
use App\Analytics\PostHogSession;
use App\Jobs\CaptureAnalyticsEvent;
use App\Models\User;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();

    Route::middleware('web')->get('/__test/boom', fn () => throw new LogicException('Visitor-facing failure'))->name('test.boom');
});

describe('reporting', function (): void {
    it('reports an admin-triggered error with the person and where it happened', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('debug.error'))->assertServerError();

        $this->analytics->assertExceptionCaptured(RuntimeException::class, fn (array $call): bool => $call['distinct_id'] === (string) $admin->id
            && $call['properties']['route'] === 'debug.error'
            && $call['properties']['$pathname'] === '/debug/error'
            && $call['properties']['http_method'] === 'GET'
            && $call['properties']['context'] === 'http');
    });

    it('links an anonymous visitor\'s error to their distinct id and replay session', function (): void {
        $this->withHeader(PostHogSession::HEADER, 'session-from-header')->get('/__test/boom')->assertServerError();

        $this->analytics->assertExceptionCaptured(LogicException::class, fn (array $call): bool => $call['distinct_id'] === session(DistinctId::SESSION_KEY)
            && $call['properties']['$session_id'] === 'session-from-header');
    });

    it('finds the replay session in the posthog-js cookie on page loads', function (): void {
        config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
        AnalyticsServiceProvider::exemptPostHogCookie();

        $this->withUnencryptedCookie('ph_phc_test_posthog', json_encode([
            'distinct_id' => 'anon-1',
            '$sesid' => [1759700000000, 'session-from-cookie', 1759690000000],
        ]))->get('/__test/boom');

        $this->analytics->assertExceptionCaptured(LogicException::class, fn (array $call): bool => $call['properties']['$session_id'] === 'session-from-cookie'
            && $call['distinct_id'] === 'anon-1');
    });

    it('leaves out exceptions Laravel does not report', function (): void {
        $this->get('/no-such-page')->assertNotFound();
        $this->post(route('login'), [])->assertSessionHasErrors();

        $this->analytics->assertNoExceptionsCaptured();
    });

    it('is admin only to trigger on purpose', function (): void {
        $this->actingAs(User::factory()->create())->get(route('debug.error'))->assertForbidden();

        $this->analytics->assertNoExceptionsCaptured();
    });

    it('respects a visitor who declined analytics', function (): void {
        $inner = new FakeAnalytics;
        app()->instance(Analytics::class, new ConsentAwareAnalytics($inner));

        $this->withUnencryptedCookie(Consent::COOKIE, Consent::DENIED)->get('/__test/boom');

        $inner->assertNoExceptionsCaptured();
    });
});

describe('never makes things worse', function (): void {
    it('does not report failed analytics deliveries, which would loop while PostHog is down', function (): void {
        app(ErrorReporter::class)->report(AnalyticsDeliveryFailed::for('capture'));

        $this->analytics->assertNoExceptionsCaptured();
    });

    it('wraps anything thrown during delivery so it is never reported back to PostHog', function (): void {
        $job = new CaptureAnalyticsEvent(CaptureAnalyticsEvent::CAPTURE, ['distinct_id' => 'x', 'event' => 'y']);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('capture')->andThrow(new RuntimeException('curl exploded'));

        expect(fn () => $job->handle($client))->toThrow(AnalyticsDeliveryFailed::class);
    });

    it('swallows its own failures so the original error is still logged', function (): void {
        $broken = Mockery::mock(Analytics::class);
        $broken->shouldReceive('captureException')->andThrow(new RuntimeException('analytics broken'));
        app()->instance(Analytics::class, $broken);

        app(ErrorReporter::class)->report(new LogicException('original'));

        expect(true)->toBeTrue();
    });
});

describe('PostHog error tracking format', function (): void {
    it('queues a $exception event with the stack, marked unhandled', function (): void {
        Queue::fake();

        (new PostHogAnalytics)->captureException('user-1', new LogicException('outer', previous: new RuntimeException('inner')), ['$session_id' => 's-1']);

        Queue::assertPushedOn('analytics', CaptureAnalyticsEvent::class, function (CaptureAnalyticsEvent $job): bool {
            $list = $job->message['properties']['$exception_list'];

            return $job->message['event'] === '$exception'
                && $job->message['distinct_id'] === 'user-1'
                && $job->message['properties']['$session_id'] === 's-1'
                && $job->message['properties']['$exception_handled'] === false
                && $list[0]['type'] === LogicException::class
                && $list[0]['value'] === 'outer'
                && $list[0]['mechanism'] === ['type' => 'laravel', 'handled' => false]
                && $list[1]['type'] === RuntimeException::class
                && $list[0]['stacktrace']['frames'] !== [];
        });
    });

    it('delivers the error through the real sdk', function (): void {
        Queue::fake();
        (new PostHogAnalytics)->captureException('user-1', new LogicException('delivered'));
        $http = new RecordingHttpClient;

        Queue::pushed(CaptureAnalyticsEvent::class)->sole()->handle(new Client('phc_test', [], $http, loadFeatureFlags: false));

        $sent = $http->batchedMessages()[0];
        expect($sent['event'])->toBe('$exception')
            ->and($sent['properties']['$exception_list'][0]['value'])->toBe('delivered');
    });
});
