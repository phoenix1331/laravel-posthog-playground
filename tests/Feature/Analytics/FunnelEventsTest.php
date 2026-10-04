<?php

declare(strict_types=1);

use App\Analytics\DistinctId;
use App\Analytics\Events;
use App\Checkout\FakePaymentGateway;
use App\Events\CheckoutStarted;
use App\Events\FixtureViewed;
use App\Events\SeatSelected;
use App\Events\TicketPurchased;
use App\Events\TravelAdded;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->fixture = Fixture::factory()->create();
    $this->allocation = TicketAllocation::factory()
        ->for($this->fixture)
        ->for(Stand::factory()->awayEnd()->create(['price_pence' => 2_500]))
        ->create(['capacity' => 500]);
    $this->coach = TravelOption::factory()->for($this->fixture)->create(['price_pence' => 1_500]);
    $this->supporter = User::factory()->inSupportersClub(SupportersClub::factory()->create(['slug' => 'millbrook-away-days']))->create();
});

function walkFunnel(TicketAllocation $allocation, TravelOption $coach, User $supporter): void
{
    test()->get(route('fixtures.show', $allocation->fixture));
    test()->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 2]);
    test()->post(route('travel.store'), ['travel_option_id' => $coach->id]);
    test()->actingAs($supporter)->get(route('checkout.show'));
    test()->post(route('checkout.store'), ['name_on_card' => 'Leah Turner', 'card_number' => FakePaymentGateway::SUCCESS_CARD]);
}

it('fires a domain event at each step of the funnel', function (): void {
    Event::fake([FixtureViewed::class, SeatSelected::class, TravelAdded::class, CheckoutStarted::class, TicketPurchased::class]);

    walkFunnel($this->allocation, $this->coach, $this->supporter);

    Event::assertDispatched(FixtureViewed::class, fn (FixtureViewed $event): bool => $event->fixture->is($this->fixture) && $event->user === null);
    Event::assertDispatched(SeatSelected::class, fn (SeatSelected $event): bool => $event->basket->quantity === 2);
    Event::assertDispatched(TravelAdded::class, fn (TravelAdded $event): bool => $event->basket->travel_option_id === $this->coach->id);
    Event::assertDispatched(CheckoutStarted::class, fn (CheckoutStarted $event): bool => $event->user->is($this->supporter));
    Event::assertDispatched(TicketPurchased::class);
});

it('captures the whole funnel as analytics events in order', function (): void {
    walkFunnel($this->allocation, $this->coach, $this->supporter);

    expect($this->analytics->capturedEvents())->toBe(Events::funnel());
});

it('describes the fixture on fixture_viewed', function (): void {
    $this->get(route('fixtures.show', $this->fixture));

    $this->analytics->assertCaptured(Events::FIXTURE_VIEWED, fn (array $call): bool => $call['properties']['fixture'] === $this->fixture->slug
        && $call['properties']['fixture_id'] === $this->fixture->id
        && ! array_key_exists('supporter_side', $call['properties']));
});

it('records stand, side, price band and quantity on seat_selected', function (): void {
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 3]);

    $this->analytics->assertCaptured(Events::SEAT_SELECTED, fn (array $call): bool => $call['properties']['supporter_side'] === 'away'
        && $call['properties']['price_band'] === 'value'
        && $call['properties']['quantity'] === 3
        && $call['properties']['unit_price_pence'] === 2_500);
});

it('reports the newly chosen stand when seats are changed', function (): void {
    $main = TicketAllocation::factory()->for($this->fixture)->for(Stand::factory()->create(['name' => 'Main Stand']))->create();
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2]);

    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $main->id, 'quantity' => 2]);

    expect($this->analytics->captured(Events::SEAT_SELECTED)->last()['properties']['stand'])->toBe('Main Stand');
});

it('includes basket totals on travel_added', function (): void {
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2]);
    $this->post(route('travel.store'), ['travel_option_id' => $this->coach->id]);

    $this->analytics->assertCaptured(Events::TRAVEL_ADDED, fn (array $call): bool => $call['properties']['has_travel'] === true
        && $call['properties']['total_pence'] === 8_000);
});

it('captures checkout_started once per basket, not on every reload', function (): void {
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2]);
    $this->actingAs($this->supporter);

    $this->get(route('checkout.show'));
    $this->get(route('checkout.show'));

    $this->analytics->assertCapturedTimes(Events::CHECKOUT_STARTED, 1);
});

it('captures ticket_purchased against the buyer with revenue and supporters club', function (): void {
    walkFunnel($this->allocation, $this->coach, $this->supporter);
    $booking = Booking::sole();

    $this->analytics->assertCaptured(Events::TICKET_PURCHASED, fn (array $call): bool => $call['distinct_id'] === (string) $this->supporter->id
        && $call['properties']['booking_reference'] === $booking->reference
        && $call['properties']['revenue'] === 80.0
        && $call['properties']['currency'] === 'GBP'
        && $call['properties']['supporters_club'] === 'millbrook-away-days'
        && $call['timestamp']->equalTo($booking->purchased_at));
});

it('does not capture a purchase when payment is declined', function (): void {
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2]);
    $this->actingAs($this->supporter)->post(route('checkout.store'), ['name_on_card' => 'Leah', 'card_number' => FakePaymentGateway::DECLINED_CARD]);

    $this->analytics->assertNotCaptured(Events::TICKET_PURCHASED);
});

it('keeps one anonymous distinct id across a visitor’s requests', function (): void {
    $this->get(route('fixtures.show', $this->fixture));
    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2]);

    $ids = collect([Events::FIXTURE_VIEWED, Events::SEAT_SELECTED])
        ->map(fn (string $name) => $this->analytics->captured($name)->sole()['distinct_id']);

    expect($ids->unique())->toHaveCount(1)
        ->and($ids->first())->toBe(session(DistinctId::SESSION_KEY))
        ->and($ids->first())->not->toBe((string) $this->supporter->id);
});

it('uses the user id once logged in, until identity stitching links the two', function (): void {
    walkFunnel($this->allocation, $this->coach, $this->supporter);

    $anonymous = $this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];
    $checkout = $this->analytics->captured(Events::CHECKOUT_STARTED)->sole()['distinct_id'];

    expect($checkout)->toBe((string) $this->supporter->id)->and($anonymous)->not->toBe($checkout);
});

it('passes backdated event times straight through', function (): void {
    $at = CarbonImmutable::parse('2026-09-01 12:00:00');
    app(DistinctId::class)->useAnonymousId('simulated-visitor');

    FixtureViewed::dispatch($this->fixture, null, $at);

    $this->analytics->assertCaptured(Events::FIXTURE_VIEWED, fn (array $call): bool => $call['distinct_id'] === 'simulated-visitor'
        && $call['timestamp']->equalTo($at));
});
