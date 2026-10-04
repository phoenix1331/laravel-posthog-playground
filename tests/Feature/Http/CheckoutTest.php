<?php

declare(strict_types=1);

use App\Checkout\FakePaymentGateway;
use App\Events\TicketPurchased;
use App\Models\Basket;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->fixture = Fixture::factory()->create();
    $this->allocation = TicketAllocation::factory()
        ->for($this->fixture)
        ->for(Stand::factory()->create(['name' => 'Main Stand', 'price_pence' => 4_000]))
        ->create(['capacity' => 10, 'sold' => 2]);
    $this->coach = TravelOption::factory()->for($this->fixture)->create(['price_pence' => 1_500, 'capacity' => 50, 'booked' => 10]);
});

function basketFor(TicketAllocation $allocation, int $quantity = 2, ?TravelOption $coach = null): Basket
{
    test()->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => $quantity]);

    if ($coach !== null) {
        test()->post(route('travel.store'), ['travel_option_id' => $coach->id]);
    }

    return Basket::sole();
}

function pay(string $card = FakePaymentGateway::SUCCESS_CARD): TestResponse
{
    return test()->post(route('checkout.store'), ['name_on_card' => 'Alex Morgan', 'card_number' => $card]);
}

it('sends guests to log in and back to checkout afterwards', function (): void {
    basketFor($this->allocation);

    $this->get(route('checkout.show'))->assertRedirect(route('login'));

    $this->post(route('login'), ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect(route('checkout.show'));
});

it('shows the order summary and test card', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation, 2, $this->coach);

    $this->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('Pay £110.00')
        ->assertSee('4242 4242 4242 4242');
});

it('books tickets and travel, takes the stock and clears the basket', function (): void {
    Event::fake([TicketPurchased::class]);
    $this->actingAs($this->user);
    basketFor($this->allocation, 3, $this->coach);

    $response = pay('4242 4242 4242 4242');

    $booking = Booking::sole();
    $response->assertRedirect(route('bookings.show', $booking));

    expect($booking->user_id)->toBe($this->user->id)
        ->and($booking->quantity)->toBe(3)
        ->and($booking->ticket_total_pence)->toBe(12_000)
        ->and($booking->travel_total_pence)->toBe(4_500)
        ->and($booking->total_pence)->toBe(16_500)
        ->and($booking->reference)->toStartWith('MD-')
        ->and($this->allocation->fresh()->sold)->toBe(5)
        ->and($this->coach->fresh()->booked)->toBe(13)
        ->and(Basket::count())->toBe(0)
        ->and(session('basket_id'))->toBeNull();

    Event::assertDispatched(TicketPurchased::class, fn (TicketPurchased $event) => $event->booking->is($booking));
});

it('shows the confirmation to its owner only', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation);
    pay();
    $booking = Booking::sole();

    $this->get(route('bookings.show', $booking))->assertOk()->assertSee($booking->reference)->assertSee('£80.00');

    $this->actingAs(User::factory()->create())
        ->get(route('bookings.show', $booking))
        ->assertNotFound();
});

it('declines the test card without booking or taking stock', function (): void {
    Event::fake([TicketPurchased::class]);
    $this->actingAs($this->user);
    basketFor($this->allocation);

    pay(FakePaymentGateway::DECLINED_CARD)->assertSessionHasErrors('card_number');

    expect(Booking::count())->toBe(0)
        ->and($this->allocation->fresh()->sold)->toBe(2)
        ->and(Basket::count())->toBe(1);
    Event::assertNotDispatched(TicketPurchased::class);
});

it('fails cleanly when the stand sells out before payment', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation, 4);
    $this->allocation->update(['sold' => 8]);

    pay()->assertSessionHasErrors('basket');

    expect(Booking::count())->toBe(0)->and($this->allocation->fresh()->sold)->toBe(8);
});

it('fails cleanly when the coach fills up before payment', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation, 2, $this->coach);
    $this->coach->update(['booked' => 49]);

    pay()->assertSessionHasErrors('basket');

    expect(Booking::count())->toBe(0)->and($this->allocation->fresh()->sold)->toBe(2);
});

it('refuses checkout once the fixture has kicked off', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation);
    $this->fixture->update(['kicks_off_at' => now()->subMinute()]);

    pay()->assertSessionHasErrors('basket');

    expect(Booking::count())->toBe(0);
});

it('validates the card details', function (): void {
    $this->actingAs($this->user);
    basketFor($this->allocation);

    $this->post(route('checkout.store'), ['name_on_card' => '', 'card_number' => '1234'])
        ->assertSessionHasErrors(['name_on_card', 'card_number']);
});
