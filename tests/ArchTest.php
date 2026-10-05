<?php

declare(strict_types=1);

arch('no native debugging or unsafe php functions')
    ->preset()
    ->php();

arch('no laravel debugging helpers')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('no insecure functions')
    ->preset()
    ->security();

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

/*
|--------------------------------------------------------------------------
| Analytics boundaries
|--------------------------------------------------------------------------
|
| Business code fires domain events; one subscriber turns them into
| analytics calls; only the analytics layer knows PostHog exists.
|
*/

// Debug pages exist to inspect the analytics layer, so they are the one exemption
arch('controllers never call PostHog or the analytics layer')
    ->expect('App\Http\Controllers')
    ->not->toUse(['PostHog', 'App\Analytics', 'App\Jobs\CaptureAnalyticsEvent'])
    ->ignoring('App\Http\Controllers\Debug');

arch('debug controllers still never call the PostHog sdk directly')
    ->expect('App\Http\Controllers\Debug')
    ->not->toUse('PostHog');

arch('the PostHog sdk is only used by the analytics layer')
    ->expect('PostHog')
    ->toOnlyBeUsedIn([
        'App\Analytics',
        'App\Jobs\CaptureAnalyticsEvent',
        'App\Providers\AnalyticsServiceProvider',
    ]);

arch('the analytics contract is only called from the subscriber and the analytics layer')
    ->expect('App\Analytics\Analytics')
    ->toOnlyBeUsedIn([
        'App\Analytics',
        'App\Listeners\AnalyticsEventSubscriber',
        'App\Providers\AnalyticsServiceProvider',
        'App\Console\Commands\SyncAnalyticsGroups',
    ]);

// One rule per namespace: with several targets in one expect([...]),
// not->toUse() only reliably checks the first (verified against Pest 5.3)
foreach (['App\Events', 'App\Models', 'App\Checkout', 'App\Basket'] as $namespace) {
    arch("{$namespace} knows nothing about analytics")
        ->expect($namespace)
        ->not->toUse(['PostHog', 'App\Analytics']);
}

arch('the analytics fake stays out of application code')
    ->expect('App\Analytics\FakeAnalytics')
    ->toOnlyBeUsedIn('App\Analytics');

arch('domain events are plain dispatchable classes')
    ->expect('App\Events')
    ->toBeClasses()
    ->toUseTrait('Illuminate\Foundation\Events\Dispatchable');
