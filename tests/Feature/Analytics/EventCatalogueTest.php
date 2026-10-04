<?php

declare(strict_types=1);

use App\Analytics\EventProperties;
use App\Analytics\Events;
use App\Enums\PriceBand;
use App\Models\Basket;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->fixture = Fixture::factory()
        ->for(Competition::factory()->create(['slug' => 'premier-division']), 'competition')
        ->for(Club::factory()->create(['slug' => 'harbour-city']), 'homeClub')
        ->for(Club::factory()->create(['slug' => 'millbrook-rovers']), 'awayClub')
        ->create(['slug' => 'harbour-city-v-millbrook-rovers', 'kicks_off_at' => now()->addDays(5)->addHour()]);
    $this->awayEnd = TicketAllocation::factory()
        ->for($this->fixture)
        ->for(Stand::factory()->awayEnd()->create(['price_pence' => 2_500]))
        ->create(['capacity' => 100, 'sold' => 95]);
});

it('names every server event in snake_case past tense', function (): void {
    expect(Events::server())->each->toMatch('/^[a-z]+(_[a-z]+)*ed$/')
        ->and(Events::server())->toHaveCount(6)
        ->and(Events::funnel()[0])->toBe(Events::FIXTURE_VIEWED)
        ->and(Events::funnel())->toContain(Events::TICKET_PURCHASED)->not->toContain(Events::CHECKOUT_ABANDONED);
});

it('describes a fixture by slugs, never by display names', function (): void {
    $properties = EventProperties::make()->fixture($this->fixture)->toArray();

    expect($properties)->toMatchArray([
        'fixture_id' => $this->fixture->id,
        'fixture' => 'harbour-city-v-millbrook-rovers',
        'competition' => 'premier-division',
        'home_club' => 'harbour-city',
        'away_club' => 'millbrook-rovers',
        'days_until_kick_off' => 5,
    ])->and($properties['kicks_off_at'])->toBe($this->fixture->kicks_off_at->toIso8601String());
});

it('measures days until kick-off from the event time, not the clock', function (): void {
    $properties = EventProperties::make(now()->subDays(10))->fixture($this->fixture)->toArray();

    expect($properties['days_until_kick_off'])->toBe(15);
});

it('marks the away end as the away side with its price band and stock', function (): void {
    $properties = EventProperties::make()->seats($this->awayEnd, 2)->toArray();

    expect($properties)->toMatchArray([
        'stand' => 'Away End',
        'price_band' => PriceBand::Value->value,
        'supporter_side' => 'away',
        'unit_price_pence' => 2_500,
        'quantity' => 2,
        'tickets_remaining' => 5,
        'near_sell_out' => true,
    ]);
});

it('marks other stands as the home side', function (): void {
    $home = TicketAllocation::factory()->for($this->fixture)->create();

    expect(EventProperties::make()->seats($home, 1)->toArray()['supporter_side'])->toBe('home');
});

it('summarises a basket with travel and totals', function (): void {
    $coach = TravelOption::factory()->for($this->fixture)->create(['price_pence' => 1_500]);
    $basket = Basket::factory()->for($this->awayEnd)->create(['quantity' => 2, 'travel_option_id' => $coach->id]);

    expect(EventProperties::make()->basket($basket)->toArray())->toMatchArray([
        'fixture' => 'harbour-city-v-millbrook-rovers',
        'supporter_side' => 'away',
        'has_travel' => true,
        'travel_price_pence' => 1_500,
        'ticket_total_pence' => 5_000,
        'travel_total_pence' => 3_000,
        'total_pence' => 8_000,
    ]);
});

it('drops null properties such as missing travel', function (): void {
    $properties = EventProperties::make()->travel(null)->supportersClub(null)->toArray();

    expect($properties)->toBe(['has_travel' => false]);
});

it('adds revenue in pounds and currency to a booking', function (): void {
    $booking = Booking::factory()->for($this->awayEnd)->create(['quantity' => 3]);

    expect(EventProperties::make()->booking($booking)->toArray())->toMatchArray([
        'booking_reference' => $booking->reference,
        'total_pence' => 7_500,
        'revenue' => 75.0,
        'currency' => 'GBP',
    ]);
});

it('identifies the supporters club by slug', function (): void {
    $supportersClub = SupportersClub::factory()->create(['slug' => 'millbrook-away-days']);

    expect(EventProperties::make()->supportersClub($supportersClub)->toArray())
        ->toBe(['supporters_club' => 'millbrook-away-days']);
});

it('refuses properties outside the allow-list', function (string $key): void {
    EventProperties::make()->with([$key => 'anything']);
})->with(['email', 'name', 'card_number', 'ip'])->throws(InvalidArgumentException::class);

it('never leaks personal data from a full booking', function (): void {
    $user = User::factory()->inSupportersClub()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com']);
    $booking = Booking::factory()->for($this->awayEnd)->for($user)->create();

    $properties = EventProperties::make()->booking($booking)->supportersClub($user->supportersClub)->toArray();

    expect(array_keys($properties))->each->toBeIn(EventProperties::ALLOWED)
        ->and(json_encode($properties))->not->toContain('Priya')->not->toContain('priya@example.com');
});
