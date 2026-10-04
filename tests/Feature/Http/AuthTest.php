<?php

declare(strict_types=1);

use App\Models\Basket;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function startBasket(): Basket
{
    $allocation = TicketAllocation::factory()->create();

    test()->post(route('seats.store', $allocation->fixture), [
        'ticket_allocation_id' => $allocation->id,
        'quantity' => 2,
    ]);

    return Basket::sole();
}

it('renders the login and registration pages', function (): void {
    SupportersClub::factory()->create(['name' => 'Harbour City Exiles']);

    $this->get(route('login'))->assertOk()->assertSee('Log in');
    $this->get(route('register'))->assertOk()->assertSee('Harbour City Exiles');
});

it('logs a supporter in and returns them to the fixture list without a basket', function (): void {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('fixtures.index'));

    $this->assertAuthenticatedAs($user);
});

it('keeps the basket through login and returns to it', function (): void {
    $basket = startBasket();
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('basket.show'));

    expect(session('basket_id'))->toBe($basket->id);
});

it('returns to the intended page after login', function (): void {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => route('basket.show')])
        ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('basket.show'));
});

it('rejects a wrong password', function (): void {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('registers a supporter with a supporters club and keeps their basket', function (): void {
    $basket = startBasket();
    $supportersClub = SupportersClub::factory()->create();

    $this->post(route('register'), [
        'name' => 'Leah Turner',
        'email' => 'leah@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'supporters_club_id' => $supportersClub->id,
    ])->assertRedirect(route('basket.show'));

    $user = User::where('email', 'leah@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->supportersClub->is($supportersClub))->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and(session('basket_id'))->toBe($basket->id);
});

it('does not let registration set admin rights', function (): void {
    $this->post(route('register'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'is_admin' => true,
    ]);

    expect(User::where('email', 'sneaky@example.com')->sole()->is_admin)->toBeFalse();
});

it('validates registration input', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register'), [
        'name' => '',
        'email' => 'taken@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'different',
        'supporters_club_id' => 999,
    ])->assertSessionHasErrors(['name', 'email', 'password', 'supporters_club_id']);
});

it('logs out and returns to the fixture list', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('fixtures.index'));

    $this->assertGuest();
});

it('prompts guests with a basket to log in or register', function (): void {
    startBasket();

    $this->get(route('basket.show'))->assertSee('to check out');
});
