<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists upcoming fixtures and hides past ones', function (): void {
    $upcoming = Fixture::factory()->create();
    $past = Fixture::factory()->past()->create();

    $this->get(route('fixtures.index'))
        ->assertOk()
        ->assertSee($upcoming->title())
        ->assertDontSee($past->title());
});

it('shows the cheapest available price and flags fixtures that are selling fast', function (): void {
    $fixture = Fixture::factory()->create();
    TicketAllocation::factory()->for($fixture)->soldOut()
        ->for(Stand::factory()->create(['price_pence' => 1_000]))->create();
    TicketAllocation::factory()->for($fixture)->nearSellOut()
        ->for(Stand::factory()->create(['price_pence' => 2_500]))->create();

    $this->get(route('fixtures.index'))
        ->assertSee('From £25.00')
        ->assertSee('Selling fast');
});

it('shows stands, availability and away travel on the fixture page', function (): void {
    $fixture = Fixture::factory()->create();
    TicketAllocation::factory()->for($fixture)->soldOut()
        ->for(Stand::factory()->awayEnd())->create();
    TicketAllocation::factory()->for($fixture)
        ->for(Stand::factory()->create(['name' => 'Main Stand', 'price_pence' => 6_100]))->create();
    TravelOption::factory()->for($fixture)->create(['departure_point' => 'Northgate Coach Station']);

    $this->get(route('fixtures.show', $fixture))
        ->assertOk()
        ->assertSee($fixture->title())
        ->assertSeeInOrder(['Main Stand', '£61.00', 'Away End', 'Sold out'])
        ->assertSee('Northgate Coach Station');
});

it('finds fixtures by slug and 404s for unknown ones', function (): void {
    $fixture = Fixture::factory()->create(['slug' => 'harbour-city-v-kingsmoor-town']);

    $this->get('/fixtures/harbour-city-v-kingsmoor-town')->assertOk()->assertSee($fixture->title());
    $this->get('/fixtures/no-such-match')->assertNotFound();
});
