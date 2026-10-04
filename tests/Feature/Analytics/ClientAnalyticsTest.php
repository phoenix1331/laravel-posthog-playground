<?php

declare(strict_types=1);

use App\Analytics\DistinctId;
use App\Analytics\Events;
use App\Models\Fixture;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sendToPostHog(): void
{
    config([
        'posthog.disabled' => false,
        'posthog.project_token' => 'phc_test',
        'posthog.secret_key' => 'phx_secret',
    ]);
}

describe('ingest proxy', function (): void {
    beforeEach(fn () => sendToPostHog());

    it('forwards events to the PostHog host with exact path, query, body and visitor ip', function (): void {
        Http::fake(['eu.i.posthog.com/*' => Http::response('{"status":1}', 200, ['Content-Type' => 'application/json'])]);

        // Straight through the kernel: the test client's call() trims trailing
        // slashes, and preserving /e/ is part of what is under test
        $response = app(HttpKernel::class)->handle(Request::create('/ingest/e/?ip=0&compression=gzip-js', 'POST', server: [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_USER_AGENT' => 'Firefox',
            'REMOTE_ADDR' => '203.0.113.9',
        ], content: 'compressed-bytes'));

        expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('{"status":1}');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://eu.i.posthog.com/e/?ip=0&compression=gzip-js'
            && $request->method() === 'POST'
            && $request->body() === 'compressed-bytes'
            && $request->header('X-Forwarded-For') === ['203.0.113.9']
            && $request->header('User-Agent') === ['Firefox']);
    });

    it('serves static bundles from the assets host', function (): void {
        Http::fake(['eu-assets.i.posthog.com/*' => Http::response('console.log(1)', 200, ['Content-Type' => 'application/javascript'])]);

        $this->get('/ingest/static/recorder.js?v=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://eu-assets.i.posthog.com/static/recorder.js?v=1');
    });

    it('passes PostHog error statuses back to the browser', function (): void {
        Http::fake(['*' => Http::response('bad', 400)]);

        $this->post('/ingest/e/')->assertStatus(400);
    });

    it('needs no csrf token and starts no session', function (): void {
        Http::fake(['*' => Http::response('ok')]);

        $response = $this->call('POST', '/ingest/flags/?v=2', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $response->assertOk();
        expect(collect($response->headers->getCookies())->map->getName())->not->toContain(config('session.cookie'));
    });

    it('never forwards our cookies to PostHog', function (): void {
        Http::fake(['*' => Http::response('ok')]);

        $this->withCookie('laravel_session', 'secret')->post('/ingest/e/');

        Http::assertSent(fn (ClientRequest $request): bool => ! $request->hasHeader('Cookie'));
    });

    it('returns 502 when PostHog cannot be reached', function (): void {
        Http::fake(['*' => Http::failedConnection()]);

        $this->post('/ingest/e/')->assertStatus(502);
    });
});

it('is not an open relay when the app is not sending', function (): void {
    Http::fake();

    $this->post('/ingest/e/')->assertNotFound();

    Http::assertNothingSent();
});

describe('posthog-js settings', function (): void {
    it('renders proxy, ui host and the server anonymous id for posthog-js', function (): void {
        sendToPostHog();

        $response = $this->get(route('fixtures.index'))->assertOk();

        preg_match('#<script type="application/json" id="posthog-config">(.+?)</script>#', $response->getContent(), $match);
        $settings = json_decode($match[1], true);

        expect($settings)->toMatchArray([
            'token' => 'phc_test',
            'apiHost' => '/ingest',
            'uiHost' => 'https://eu.posthog.com',
        ])->and($settings['bootstrap']['distinctID'])->toBe(session(DistinctId::SESSION_KEY));
    });

    it('bootstraps the browser with the same id the server captures with', function (): void {
        sendToPostHog();
        $analytics = fakeAnalytics();
        $fixture = Fixture::factory()->create();

        $page = $this->get(route('fixtures.show', $fixture))->getContent();

        $captured = $analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];
        expect($page)->toContain('"distinctID":"'.$captured.'"');
    });

    it('never exposes the secret key', function (): void {
        sendToPostHog();

        $this->get(route('fixtures.index'))->assertDontSee('phx_secret');
    });

    it('renders nothing when the app is not sending', function (): void {
        $this->get(route('fixtures.index'))->assertDontSee('posthog-config');
    });
});

it('adopts the anonymous id from the posthog-js cookie', function (): void {
    sendToPostHog();
    AnalyticsServiceProvider::exemptPostHogCookie();
    $analytics = fakeAnalytics();
    $fixture = Fixture::factory()->create();

    $this->withUnencryptedCookie('ph_phc_test_posthog', json_encode(['distinct_id' => 'browser-anon-1', '$device_id' => 'device-1']))
        ->get(route('fixtures.show', $fixture));

    $analytics->assertCaptured(Events::FIXTURE_VIEWED, fn (array $call): bool => $call['distinct_id'] === 'browser-anon-1');
    expect(session(DistinctId::SESSION_KEY))->toBe('browser-anon-1');
});

it('ignores a malformed posthog-js cookie', function (string $value): void {
    sendToPostHog();
    AnalyticsServiceProvider::exemptPostHogCookie();
    $analytics = fakeAnalytics();
    $fixture = Fixture::factory()->create();

    $this->withUnencryptedCookie('ph_phc_test_posthog', $value)->get(route('fixtures.show', $fixture));

    expect($analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'])->toBeUuid();
})->with([
    'not json' => ['nope'],
    'no distinct id' => ['{"$device_id":"x"}'],
    'not a string' => ['{"distinct_id":42}'],
    'too long' => [json_encode(['distinct_id' => str_repeat('a', 201)])],
]);
