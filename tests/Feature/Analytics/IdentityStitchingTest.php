<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\Events;
use App\Analytics\PersonProperties;
use App\Checkout\FakePaymentGateway;
use App\Jobs\CaptureAnalyticsEvent;
use App\Models\Fixture;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\User;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->supportersClub = SupportersClub::factory()->create(['slug' => 'harbour-city-exiles']);
    $this->user = User::factory()->inSupportersClub($this->supportersClub)->create([
        'name' => 'Priya Shah',
        'email' => 'priya@example.com',
        'created_at' => '2026-09-01 10:00:00',
    ]);
});

function logIn(User $user): void
{
    test()->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
}

it('aliases the anonymous browsing id to the user id on login', function (): void {
    $this->get(route('fixtures.show', Fixture::factory()->create()));
    $anonymousId = $this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];

    logIn($this->user);

    $this->analytics->assertAliased((string) $this->user->id, $anonymousId);
});

it('identifies the supporter with segmentable, non-personal person properties', function (): void {
    logIn($this->user);

    $this->analytics->assertIdentified((string) $this->user->id, fn (array $properties): bool => $properties === [
        'supporters_club' => 'harbour-city-exiles',
        'has_supporters_club' => true,
        'signed_up_at' => '2026-09-01',
    ]);
});

it('stitches on registration too', function (): void {
    $this->get(route('fixtures.show', Fixture::factory()->create()));
    $anonymousId = $this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];

    $this->post(route('register'), [
        'name' => 'Owen Hughes',
        'email' => 'owen@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect();

    $user = User::where('email', 'owen@example.com')->sole();
    $this->analytics->assertAliased((string) $user->id, $anonymousId);
    $this->analytics->assertIdentified((string) $user->id, fn (array $properties): bool => $properties['has_supporters_club'] === false
        && ! array_key_exists('supporters_club', $properties));
});

it('does not alias when the browser already identified as the user', function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
    AnalyticsServiceProvider::exemptPostHogCookie();
    $this->analytics = fakeAnalytics();

    $this->withUnencryptedCookie('ph_phc_test_posthog', json_encode(['distinct_id' => (string) $this->user->id]))
        ->post(route('login'), ['email' => $this->user->email, 'password' => 'password']);

    $this->analytics->assertIdentified((string) $this->user->id);
    expect(fn () => $this->analytics->assertAliased((string) $this->user->id, (string) $this->user->id))
        ->toThrow(ExpectationFailedException::class);
});

it('does not stitch on a failed login', function (): void {
    $this->post(route('login'), ['email' => $this->user->email, 'password' => 'wrong']);

    expect(fn () => $this->analytics->assertIdentified((string) $this->user->id))
        ->toThrow(ExpectationFailedException::class);
});

it('never puts a name or email on the person', function (): void {
    expect(json_encode(PersonProperties::for($this->user)))
        ->not->toContain('Priya')
        ->not->toContain('priya@example.com');
});

it('links the whole funnel to one person once aliased', function (): void {
    $allocation = TicketAllocation::factory()->create();
    $this->get(route('fixtures.show', $allocation->fixture));
    $this->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 1]);

    logIn($this->user);
    $this->get(route('checkout.show'));
    $this->post(route('checkout.store'), ['name_on_card' => 'P Shah', 'card_number' => FakePaymentGateway::SUCCESS_CARD]);

    $anonymousId = $this->analytics->captured(Events::SEAT_SELECTED)->sole()['distinct_id'];
    $buyerId = $this->analytics->captured(Events::TICKET_PURCHASED)->sole()['distinct_id'];

    expect($anonymousId)->not->toBe($buyerId);
    $this->analytics->assertAliased($buyerId, $anonymousId);
});

it('queues alias before identify for PostHog', function (): void {
    Queue::fake();
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
    app()->forgetInstance(Analytics::class);
    $this->get(route('fixtures.index'));

    logIn($this->user);

    expect(Queue::pushed(CaptureAnalyticsEvent::class)->pluck('call')->all())
        ->toBe([CaptureAnalyticsEvent::ALIAS, CaptureAnalyticsEvent::IDENTIFY]);
});
