<?php

declare(strict_types=1);

use App\Models\Basket;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->fixture = Fixture::factory()->create();
    $this->allocation = TicketAllocation::factory()
        ->for($this->fixture)
        ->for(Stand::factory()->create(['name' => 'North Stand', 'price_pence' => 3_000]))
        ->create(['capacity' => 100]);
    $this->coach = TravelOption::factory()->for($this->fixture)->create([
        'departure_point' => 'Millbrook Coach Station',
        'price_pence' => 1_500,
    ]);
});

function selectSeats(TicketAllocation $allocation, int $quantity = 2): TestResponse
{
    return test()->post(route('seats.store', $allocation->fixture), [
        'ticket_allocation_id' => $allocation->id,
        'quantity' => $quantity,
    ]);
}

it('puts selected seats in a persisted basket tracked by the session', function (): void {
    selectSeats($this->allocation, 2)->assertRedirect(route('basket.show'));

    $basket = Basket::sole();

    expect($basket->fixture_id)->toBe($this->fixture->id)
        ->and($basket->ticket_allocation_id)->toBe($this->allocation->id)
        ->and($basket->quantity)->toBe(2)
        ->and(session('basket_id'))->toBe($basket->id);

    $this->get(route('basket.show'))
        ->assertOk()
        ->assertSee('2 &times; North Stand', false)
        ->assertSee('£60.00')
        ->assertSee('Millbrook Coach Station');
});

it('reuses the basket when changing seats for the same fixture', function (): void {
    selectSeats($this->allocation, 2);
    selectSeats($this->allocation, 4);

    expect(Basket::count())->toBe(1)
        ->and(Basket::sole()->quantity)->toBe(4);
});

it('starts a new basket when selecting seats for a different fixture', function (): void {
    selectSeats($this->allocation);
    $otherAllocation = TicketAllocation::factory()->create();

    selectSeats($otherAllocation);

    expect(Basket::find(session('basket_id'))->fixture_id)->toBe($otherAllocation->fixture_id);
});

it('rejects seats from another fixture, too many tickets and sold out stands', function (array $overrides, string $errorField): void {
    $payload = ['ticket_allocation_id' => $this->allocation->id, 'quantity' => 2];

    if (($overrides['ticket_allocation_id'] ?? null) === 'other') {
        $overrides['ticket_allocation_id'] = TicketAllocation::factory()->create()->id;
    }

    if (($overrides['ticket_allocation_id'] ?? null) === 'sold-out') {
        $overrides['ticket_allocation_id'] = TicketAllocation::factory()->for($this->fixture)->soldOut()->create()->id;
    }

    $this->post(route('seats.store', $this->fixture), array_merge($payload, $overrides))
        ->assertSessionHasErrors($errorField);

    expect(Basket::count())->toBe(0);
})->with([
    'other fixture' => [['ticket_allocation_id' => 'other'], 'ticket_allocation_id'],
    'over the limit' => [['quantity' => Basket::MAX_TICKETS + 1], 'quantity'],
    'zero tickets' => [['quantity' => 0], 'quantity'],
    'sold out stand' => [['ticket_allocation_id' => 'sold-out'], 'quantity'],
]);

it('rejects seats for a fixture that has kicked off', function (): void {
    $past = Fixture::factory()->past()->create();
    $allocation = TicketAllocation::factory()->for($past)->create();

    selectSeats($allocation)->assertSessionHasErrors('ticket_allocation_id');
});

it('adds and removes away travel priced per ticket', function (): void {
    selectSeats($this->allocation, 2);

    $this->post(route('travel.store'), ['travel_option_id' => $this->coach->id])
        ->assertRedirect(route('basket.show'));

    expect(Basket::sole()->travel_option_id)->toBe($this->coach->id);
    $this->get(route('basket.show'))->assertSee('£30.00')->assertSee('£90.00');

    $this->delete(route('travel.destroy'))->assertRedirect(route('basket.show'));

    expect(Basket::sole()->travel_option_id)->toBeNull();
});

it('rejects a coach for another fixture or without enough seats', function (): void {
    selectSeats($this->allocation, 4);
    $otherCoach = TravelOption::factory()->create();
    $fullCoach = TravelOption::factory()->for($this->fixture)->create(['capacity' => 50, 'booked' => 47]);

    $this->post(route('travel.store'), ['travel_option_id' => $otherCoach->id])->assertSessionHasErrors('travel_option_id');
    $this->post(route('travel.store'), ['travel_option_id' => $fullCoach->id])->assertSessionHasErrors('travel_option_id');

    expect(Basket::sole()->travel_option_id)->toBeNull();
});

it('drops the coach when more tickets are selected than it has seats for', function (): void {
    $smallCoach = TravelOption::factory()->for($this->fixture)->create(['capacity' => 50, 'booked' => 47]);
    selectSeats($this->allocation, 2);
    $this->post(route('travel.store'), ['travel_option_id' => $smallCoach->id]);

    selectSeats($this->allocation, 4);

    expect(Basket::sole()->travel_option_id)->toBeNull();
});

it('sends visitors without a basket back to the fixture list', function (): void {
    $this->get(route('basket.show'))->assertRedirect(route('fixtures.index'));
    $this->post(route('travel.store'), ['travel_option_id' => $this->coach->id])->assertRedirect(route('fixtures.index'));
});
