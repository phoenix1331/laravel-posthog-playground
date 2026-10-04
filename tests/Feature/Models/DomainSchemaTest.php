<?php

declare(strict_types=1);

use App\Enums\PriceBand;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a fixture to its competition, clubs, allocations and travel', function (): void {
    $fixture = Fixture::factory()
        ->has(TicketAllocation::factory()->count(2))
        ->has(TravelOption::factory())
        ->create();

    expect($fixture->competition)->not->toBeNull()
        ->and($fixture->homeClub)->toBeInstanceOf(Club::class)
        ->and($fixture->awayClub)->toBeInstanceOf(Club::class)
        ->and($fixture->ticketAllocations)->toHaveCount(2)
        ->and($fixture->travelOptions)->toHaveCount(1)
        ->and($fixture->title())->toBe("{$fixture->homeClub->name} v {$fixture->awayClub->name}");
});

it('routes fixtures by slug', function (): void {
    expect((new Fixture)->getRouteKeyName())->toBe('slug');
});

it('only returns future fixtures in kick-off order from the upcoming scope', function (): void {
    $later = Fixture::factory()->create(['kicks_off_at' => now()->addDays(10)]);
    $sooner = Fixture::factory()->create(['kicks_off_at' => now()->addDays(2)]);
    Fixture::factory()->past()->create();

    expect(Fixture::upcoming()->pluck('id')->all())->toBe([$sooner->id, $later->id]);
});

it('casts stand price bands to the enum', function (): void {
    $stand = Stand::factory()->band(PriceBand::Premium, 5_500)->create();

    expect($stand->fresh()->price_band)->toBe(PriceBand::Premium);
});

it('reports remaining stock and sell-out state on allocations', function (): void {
    $open = TicketAllocation::factory()->create(['capacity' => 100, 'sold' => 40]);
    $near = TicketAllocation::factory()->nearSellOut()->create(['capacity' => 100]);
    $gone = TicketAllocation::factory()->soldOut()->create(['capacity' => 100]);

    expect($open->remaining())->toBe(60)
        ->and($open->isNearSellOut())->toBeFalse()
        ->and($near->isNearSellOut())->toBeTrue()
        ->and($near->isSoldOut())->toBeFalse()
        ->and($gone->isSoldOut())->toBeTrue();
});

it('totals a booking from the stand price and quantity', function (): void {
    $stand = Stand::factory()->create(['price_pence' => 3_000]);
    $allocation = TicketAllocation::factory()->for($stand)->create();

    $booking = Booking::factory()->for($allocation)->create(['quantity' => 3]);

    expect($booking->fixture_id)->toBe($allocation->fixture_id)
        ->and($booking->ticket_total_pence)->toBe(9_000)
        ->and($booking->total_pence)->toBe(9_000);
});

it('places supporters in a supporters club', function (): void {
    $supportersClub = SupportersClub::factory()->create();
    $supporter = User::factory()->inSupportersClub($supportersClub)->create();
    $admin = User::factory()->admin()->create();

    expect($supporter->supportersClub->is($supportersClub))->toBeTrue()
        ->and($supportersClub->members)->toHaveCount(1)
        ->and($admin->is_admin)->toBeTrue()
        ->and($supporter->is_admin)->toBeFalse();
});
