<?php

declare(strict_types=1);

use App\Analytics\Events;
use App\Events\CheckoutAbandoned;
use App\Jobs\DetectAbandonedCheckouts;
use App\Models\Basket;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-04 15:00:00');
    $this->user = User::factory()->create();
});

function startedBasket(User $user, string $lastActivity, array $attributes = []): Basket
{
    $basket = Basket::factory()->create([
        'user_id' => $user->id,
        'checkout_started_at' => $lastActivity,
        ...$attributes,
    ]);
    $basket->forceFill(['updated_at' => $lastActivity])->saveQuietly();

    return $basket;
}

it('flags baskets idle at checkout for 30 minutes and fires CheckoutAbandoned', function (): void {
    Event::fake([CheckoutAbandoned::class]);
    $idle = startedBasket($this->user, '2026-10-04 14:20:00');

    (new DetectAbandonedCheckouts)->handle();

    expect($idle->fresh()->abandoned_at)->not->toBeNull();
    Event::assertDispatched(CheckoutAbandoned::class, fn (CheckoutAbandoned $event): bool => $event->basket->is($idle)
        && $event->user->is($this->user)
        && $event->occurredAt->equalTo(Carbon::parse('2026-10-04 14:50:00')));
});

it('leaves recent, not-started, anonymous and already flagged baskets alone', function (): void {
    Event::fake([CheckoutAbandoned::class]);
    startedBasket($this->user, '2026-10-04 14:45:00');
    Basket::factory()->create(['updated_at' => '2026-10-04 13:00:00']);
    startedBasket($this->user, '2026-10-04 13:00:00', ['user_id' => null]);
    startedBasket($this->user, '2026-10-04 13:00:00', ['abandoned_at' => '2026-10-04 13:30:00']);

    (new DetectAbandonedCheckouts)->handle();

    Event::assertNotDispatched(CheckoutAbandoned::class);
});

it('counts exactly 30 minutes idle as abandoned', function (): void {
    Event::fake([CheckoutAbandoned::class]);
    startedBasket($this->user, '2026-10-04 14:30:00');

    (new DetectAbandonedCheckouts)->handle();

    Event::assertDispatchedTimes(CheckoutAbandoned::class, 1);
});

it('fires once however many times the sweep runs', function (): void {
    Event::fake([CheckoutAbandoned::class]);
    startedBasket($this->user, '2026-10-04 14:00:00');

    (new DetectAbandonedCheckouts)->handle();
    (new DetectAbandonedCheckouts)->handle();

    Event::assertDispatchedTimes(CheckoutAbandoned::class, 1);
});

it('captures checkout_abandoned against the supporter at the moment it became abandoned', function (): void {
    $analytics = fakeAnalytics();
    $basket = startedBasket($this->user, '2026-10-04 14:10:00');

    (new DetectAbandonedCheckouts)->handle();

    $analytics->assertCaptured(Events::CHECKOUT_ABANDONED, fn (array $call): bool => $call['distinct_id'] === (string) $this->user->id
        && $call['properties']['total_pence'] === $basket->totalPence()
        && $call['properties']['quantity'] === $basket->quantity
        && $call['timestamp']->equalTo(Carbon::parse('2026-10-04 14:40:00')));
});

it('records who reached checkout so abandonment can be attributed', function (): void {
    $basket = Basket::factory()->create();
    $this->withSession(['basket_id' => $basket->id])->actingAs($this->user)->get(route('checkout.show'));

    expect($basket->fresh())
        ->user_id->toBe($this->user->id)
        ->checkout_started_at->not->toBeNull();
});

it('runs the sweep every five minutes without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'Flag baskets idle at checkout as abandoned');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
