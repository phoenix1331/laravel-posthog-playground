<?php

declare(strict_types=1);

use App\Analytics\Events;
use App\Analytics\FakeAnalytics;
use App\Analytics\Flags\PostHogFlags;
use App\Analytics\Flags\Rollout;
use App\Features\NewSeatPicker;
use App\Models\Basket;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\TicketAllocation;
use App\Models\User;
use App\Simulation\Simulator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 12:00:00');
    $this->seed(DatabaseSeeder::class);
    $this->analytics = fakeAnalytics();
});

/**
 * @return Collection<int, array{distinct_id: string, event: string, properties: array<string, scalar>, groups: array<string, string>, timestamp: Carbon\CarbonInterface|null}>
 */
function allCaptured(FakeAnalytics $analytics): Collection
{
    return collect(Events::server())->flatMap(fn (string $event) => $analytics->captured($event));
}

it('produces a funnel with drop-off at every step', function (): void {
    $report = app(Simulator::class)->run(300, 30, seed: 1331);

    $viewed = $report->count(Events::FIXTURE_VIEWED);
    $selected = $report->count(Events::SEAT_SELECTED);
    $started = $report->count(Events::CHECKOUT_STARTED);
    $purchased = $report->count(Events::TICKET_PURCHASED);

    expect($viewed)->toBeGreaterThan($selected)
        ->and($selected)->toBeGreaterThan($started)
        ->and($started)->toBeGreaterThan($purchased)
        ->and($purchased)->toBeGreaterThan(0)
        ->and($report->count(Events::TRAVEL_ADDED))->toBeGreaterThan(0)
        ->and($purchased + $report->count(Events::CHECKOUT_ABANDONED))->toBe($started);
});

it('sends every event through the analytics contract, matching its own report', function (): void {
    $report = app(Simulator::class)->run(100, 30, seed: 7);

    foreach ($report->events() as $event => $count) {
        $this->analytics->assertCapturedTimes($event, $count);
    }
});

it('browses anonymously, logs in at checkout and stitches the two ids', function (): void {
    $report = app(Simulator::class)->run(150, 30, seed: 42);

    $captured = allCaptured($this->analytics);
    expect($captured->pluck('distinct_id'))->each->toMatch('/^sim-(anon|user)-[0-9a-f]{16}$/')
        ->and($report->logins())->toBe($report->count(Events::CHECKOUT_STARTED));

    $this->analytics->captured(Events::TICKET_PURCHASED)->each(function (array $purchase) use ($captured): void {
        expect($purchase['distinct_id'])->toStartWith('sim-user-');

        $anonymousIds = $this->analytics->aliasesOf($purchase['distinct_id']);
        $browsing = $captured->whereIn('distinct_id', $anonymousIds);

        expect($anonymousIds)->toHaveCount(1)
            ->and($anonymousIds[0])->toStartWith('sim-anon-')
            ->and($browsing->pluck('event'))->toContain(Events::FIXTURE_VIEWED, Events::SEAT_SELECTED)
            ->and($browsing->min('timestamp')->lessThan($purchase['timestamp']))->toBeTrue();
    });
});

it('identifies simulated supporters through the app\'s own login stitching', function (): void {
    app(Simulator::class)->run(100, 30, seed: 8);

    $buyer = $this->analytics->captured(Events::TICKET_PURCHASED)->first()['distinct_id'];

    $this->analytics->assertIdentified($buyer);
});

it('without stitching, leaves every purchase on a person who never viewed a fixture', function (): void {
    $report = app(Simulator::class)->run(150, 30, seed: 42, stitching: false);

    $captured = allCaptured($this->analytics);
    $viewers = $captured->where('event', Events::FIXTURE_VIEWED)->pluck('distinct_id')->unique();
    $buyers = $this->analytics->captured(Events::TICKET_PURCHASED)->pluck('distinct_id');

    expect($buyers)->not->toBeEmpty()
        ->and($buyers->intersect($viewers))->toBeEmpty()
        ->and($buyers->flatMap(fn (string $buyer) => $this->analytics->aliasesOf($buyer)))->toBeEmpty()
        ->and($report->logins())->toBe(0)
        ->and($report->viewToPurchaseRate())->toBe(0.0);
});

it('reports the per-person view to purchase rate PostHog will show', function (): void {
    $stitched = app(Simulator::class)->run(300, 30, seed: 1331);
    $purchases = $stitched->count(Events::TICKET_PURCHASED);

    expect($stitched->viewToPurchaseRate())->toBe(round($purchases / 300 * 100, 1))
        ->and($stitched->viewToPurchaseRate())->toBeGreaterThan(0.0);
});

it('backdates events inside the window and never into the future', function (): void {
    app(Simulator::class)->run(150, 10, seed: 3);

    allCaptured($this->analytics)->each(function (array $call): void {
        expect($call['timestamp']->greaterThanOrEqualTo(now()->subDays(10)))->toBeTrue()
            ->and($call['timestamp']->lessThan(now()))->toBeTrue();
    });
});

it('sends loyal away fans to the away end with a coach and their supporters club', function (): void {
    app(Simulator::class)->run(200, 30, seed: 11);

    $this->analytics->assertCaptured(Events::SEAT_SELECTED, fn (array $call): bool => $call['properties']['supporter_side'] === 'away');
    $this->analytics->assertCaptured(Events::TICKET_PURCHASED, fn (array $call): bool => $call['properties']['has_travel'] === true
        && isset($call['properties']['supporters_club']));
});

it('is repeatable with a seed', function (): void {
    app(Simulator::class)->run(80, 30, seed: 99);
    $first = allCaptured($this->analytics)->map(fn (array $call): string => $call['distinct_id'].$call['event'].$call['timestamp'])->all();

    $this->analytics = fakeAnalytics();
    app(Simulator::class)->run(80, 30, seed: 99);
    $second = allCaptured($this->analytics)->map(fn (array $call): string => $call['distinct_id'].$call['event'].$call['timestamp'])->all();

    expect($second)->toBe($first);
});

it('leaves stock, users, baskets and bookings untouched', function (): void {
    $sold = TicketAllocation::sum('sold');
    $users = User::count();

    app(Simulator::class)->run(200, 30, seed: 5);

    expect(TicketAllocation::sum('sold'))->toBe($sold)
        ->and(User::count())->toBe($users)
        ->and(Basket::count())->toBe(0)
        ->and(Booking::count())->toBe(0);
});

it('runs from artisan and prints the funnel', function (): void {
    $this->artisan('matchday:simulate', ['--users' => 50, '--days' => 7, '--seed' => 1])
        ->expectsOutputToContain('No PostHog token')
        ->expectsOutputToContain('fixture_viewed')
        ->expectsOutputToContain('events generated')
        ->expectsOutputToContain('logins aliased')
        ->assertSuccessful();
});

it('warns that the funnel breaks when run without stitching', function (): void {
    $this->artisan('matchday:simulate', ['--users' => 50, '--seed' => 1, '--no-stitching' => true])
        ->expectsOutputToContain('never viewed a fixture')
        ->assertSuccessful();
});

it('rejects nonsense options', function (array $options): void {
    $this->artisan('matchday:simulate', $options)->assertExitCode(2);
})->with([
    'no users' => [['--users' => 0]],
    'no days' => [['--days' => 0]],
]);

it('explains what to do when there are no fixtures', function (): void {
    Fixture::query()->delete();

    $this->artisan('matchday:simulate', ['--users' => 5])
        ->expectsOutputToContain('make fresh')
        ->assertFailed();
});

describe('feature flags', function (): void {
    it('assigns the seat picker through Pennant, so overriding the flag changes every supporter', function (): void {
        Feature::define('new-seat-picker', true);

        $report = app(Simulator::class)->run(40, 30, seed: 2);

        expect($report->seatPickerVariants()['true']['supporters'])->toBe(40)
            ->and($report->seatPickerVariants()['false']['supporters'])->toBe(0);
    });

    it('gives each supporter the variant the app would, via the same rollout', function (): void {
        $report = app(Simulator::class)->run(300, 30, seed: 21);

        $anonymousIds = $this->analytics->captured(Events::FIXTURE_VIEWED)->pluck('distinct_id')->unique();
        $inRollout = $anonymousIds->filter(fn (string $id): bool => Rollout::includes('new-seat-picker', $id, NewSeatPicker::ROLLOUT_PERCENTAGE));

        expect($anonymousIds)->toHaveCount(300)
            ->and($report->seatPickerVariants()['true']['supporters'])->toBe($inRollout->count());
    });

    it('shows the stadium map lifting seat selection', function (): void {
        $variants = app(Simulator::class)->run(1000, 30, seed: 1331)->seatPickerVariants();

        expect($variants['true']['rate'])->toBeGreaterThan($variants['false']['rate'])
            ->and($variants['true']['supporters'] / 1000)->toBeGreaterThan(0.15)->toBeLessThan(0.25);
    });

    it('records backdated exposure for each supporter when PostHog serves the flag', function (): void {
        config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test', 'pennant.default' => 'posthog']);
        app()->instance(PostHogFlags::class, new PostHogFlags(
            makeClient: fn () => new Client('phc_test', [], new RecordingHttpClient(bodies: [
                '/flags/' => '{"flags": {"new-seat-picker": {"key": "new-seat-picker", "enabled": true, "variant": null}}}',
            ]), loadFeatureFlags: false),
            mode: PostHogFlags::REMOTE,
        ));
        Feature::forgetDrivers();

        app(Simulator::class)->run(20, 10, seed: 4);

        $exposures = $this->analytics->captured(Events::FEATURE_FLAG_CALLED);
        expect($exposures)->toHaveCount(20);

        $exposures->each(function (array $exposure): void {
            $firstView = $this->analytics->captured(Events::FIXTURE_VIEWED)->where('distinct_id', $exposure['distinct_id'])->min('timestamp');

            expect($exposure['properties'])->toBe(['$feature_flag' => 'new-seat-picker', '$feature_flag_response' => true])
                ->and($exposure['timestamp']->lessThanOrEqualTo($firstView))->toBeTrue()
                ->and($exposure['timestamp']->lessThan(now()->subHours(2)))->toBeTrue();
        });
    });
});
