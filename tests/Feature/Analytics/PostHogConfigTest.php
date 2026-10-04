<?php

declare(strict_types=1);

it('defaults to PostHog Cloud EU', function (): void {
    expect(config('posthog.host'))->toBe('https://eu.i.posthog.com')
        ->and(config('posthog.assets_host'))->toBe('https://eu-assets.i.posthog.com')
        ->and(config('posthog.ui_host'))->toBe('https://eu.posthog.com');
});

it('never talks to PostHog from the test suite', function (): void {
    expect(config('posthog.disabled'))->toBeTrue()
        ->and(config('posthog.project_token'))->toBeEmpty()
        ->and(config('posthog.secret_key'))->toBeEmpty();
});

it('captures on a dedicated queue through the ingest proxy path', function (): void {
    expect(config('posthog.queue'))->toBe('analytics')
        ->and(config('posthog.proxy_path'))->toBe('ingest');
});

it('passes millisecond timeouts and a consumer to the sdk client', function (): void {
    expect(config('posthog.client'))->toMatchArray([
        'consumer' => 'lib_curl',
        'timeout' => 3000,
        'feature_flag_request_timeout_ms' => 1500,
        'debug' => false,
    ]);
});
