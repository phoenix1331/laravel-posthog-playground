<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\FakeAnalytics;
use App\Analytics\NullAnalytics;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Log\LoggerInterface;

it('resolves the null driver when there is no project token', function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => null]);

    expect(AnalyticsServiceProvider::sending())->toBeFalse()
        ->and(app(Analytics::class))->toBeInstanceOf(NullAnalytics::class);
});

it('does not send when switched off even with a token', function (): void {
    config(['posthog.disabled' => true, 'posthog.project_token' => 'phc_test']);

    expect(AnalyticsServiceProvider::sending())->toBeFalse();
});

it('sends only with a token and the switch on', function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);

    expect(AnalyticsServiceProvider::sending())->toBeTrue();
});

it('resolves one shared instance', function (): void {
    expect(app(Analytics::class))->toBe(app(Analytics::class));
});

it('drops calls silently on the null driver without a log channel', function (): void {
    Log::spy();

    $analytics = new NullAnalytics;
    $analytics->capture('user-1', 'fixture_viewed', ['fixture' => 'a-v-b']);
    $analytics->identify('user-1');

    Log::shouldNotHaveReceived('debug');
});

it('writes calls to the configured log channel on the null driver', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    $analytics = new NullAnalytics($logger);

    $analytics->capture('user-1', 'fixture_viewed', ['fixture' => 'a-v-b'], ['supporters_club' => 'exiles'], now());
    $analytics->alias('user-1', 'anon-1');

    $logger->shouldHaveReceived('debug')->with('analytics.capture', Mockery::on(
        fn (array $context): bool => $context['event'] === 'fixture_viewed'
            && $context['properties'] === ['fixture' => 'a-v-b']
            && $context['groups'] === ['supporters_club' => 'exiles'],
    ));
    $logger->shouldHaveReceived('debug')->with('analytics.alias', ['distinct_id' => 'user-1', 'alias' => 'anon-1']);
});

it('swaps in a recording fake with the helper', function (): void {
    $fake = fakeAnalytics();

    app(Analytics::class)->capture('user-1', 'seat_selected', ['quantity' => 2], ['supporters_club' => 'exiles']);
    app(Analytics::class)->identify('user-1', ['supporters_club' => 'exiles']);
    app(Analytics::class)->alias('user-1', 'anon-1');
    app(Analytics::class)->groupIdentify('supporters_club', 'exiles', ['club' => 'harbour-city']);

    expect(app(Analytics::class))->toBe($fake);
    $fake->assertCaptured('seat_selected', fn (array $call): bool => $call['properties']['quantity'] === 2
        && $call['groups'] === ['supporters_club' => 'exiles']);
    $fake->assertCapturedTimes('seat_selected', 1);
    $fake->assertNotCaptured('ticket_purchased');
    $fake->assertIdentified('user-1', fn (array $properties): bool => $properties['supporters_club'] === 'exiles');
    $fake->assertAliased('user-1', 'anon-1');
    $fake->assertGroupIdentified('supporters_club', 'exiles');
});

it('fails fake assertions when the call did not happen', function (Closure $assertion): void {
    $fake = new FakeAnalytics;
    $fake->capture('user-1', 'fixture_viewed');

    expect(fn () => $assertion($fake))->toThrow(ExpectationFailedException::class);
})->with([
    'captured' => [fn (FakeAnalytics $fake) => $fake->assertCaptured('ticket_purchased')],
    'captured with filter' => [fn (FakeAnalytics $fake) => $fake->assertCaptured('fixture_viewed', fn (): bool => false)],
    'times' => [fn (FakeAnalytics $fake) => $fake->assertCapturedTimes('fixture_viewed', 2)],
    'not captured' => [fn (FakeAnalytics $fake) => $fake->assertNotCaptured('fixture_viewed')],
    'nothing captured' => [fn (FakeAnalytics $fake) => $fake->assertNothingCaptured()],
    'identified' => [fn (FakeAnalytics $fake) => $fake->assertIdentified('user-1')],
    'aliased' => [fn (FakeAnalytics $fake) => $fake->assertAliased('user-1', 'anon-1')],
    'group' => [fn (FakeAnalytics $fake) => $fake->assertGroupIdentified('supporters_club', 'exiles')],
]);
