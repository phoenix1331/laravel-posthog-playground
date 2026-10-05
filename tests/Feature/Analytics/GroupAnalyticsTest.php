<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\Events;
use App\Analytics\Groups;
use App\Checkout\FakePaymentGateway;
use App\Jobs\CaptureAnalyticsEvent;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->supportersClub = SupportersClub::factory()
        ->for(Club::factory()->create(['slug' => 'harbour-city']))
        ->create(['name' => 'Harbour City Exiles', 'slug' => 'harbour-city-exiles']);
    $this->member = User::factory()->inSupportersClub($this->supportersClub)->create();
});

it('attaches the supporters club group to a member\'s events', function (): void {
    $this->actingAs($this->member)->get(route('fixtures.show', Fixture::factory()->create()));

    $this->analytics->assertCaptured(Events::FIXTURE_VIEWED, fn (array $call): bool => $call['groups'] === ['supporters_club' => 'harbour-city-exiles']);
});

it('sends no groups for anonymous visitors or supporters without a club', function (): void {
    $fixture = Fixture::factory()->create();
    $this->get(route('fixtures.show', $fixture));
    $this->actingAs(User::factory()->create())->get(route('fixtures.show', $fixture));

    expect($this->analytics->captured(Events::FIXTURE_VIEWED)->pluck('groups')->all())->toBe([[], []]);
});

it('credits a purchase to the buyer\'s supporters club', function (): void {
    $allocation = TicketAllocation::factory()->create();
    $this->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 2]);
    $this->actingAs($this->member)->get(route('checkout.show'));
    $this->post(route('checkout.store'), ['name_on_card' => 'Member', 'card_number' => FakePaymentGateway::SUCCESS_CARD]);

    $this->analytics->assertCaptured(Events::TICKET_PURCHASED, fn (array $call): bool => $call['groups'] === ['supporters_club' => 'harbour-city-exiles']);
});

it('refreshes the group\'s properties when a member logs in', function (): void {
    User::factory()->count(2)->inSupportersClub($this->supportersClub)->create();

    $this->post(route('login'), ['email' => $this->member->email, 'password' => 'password']);

    $this->analytics->assertGroupIdentified(Groups::SUPPORTERS_CLUB, 'harbour-city-exiles');
    expect(Groups::properties($this->supportersClub))->toBe([
        'name' => 'Harbour City Exiles',
        'club' => 'harbour-city',
        'members' => 3,
    ]);
});

it('does not identify a group for supporters without a club', function (): void {
    $loner = User::factory()->create();

    $this->post(route('login'), ['email' => $loner->email, 'password' => 'password']);

    expect(fn () => $this->analytics->assertGroupIdentified(Groups::SUPPORTERS_CLUB, 'harbour-city-exiles'))
        ->toThrow(ExpectationFailedException::class);
});

it('syncs every supporters club as a group from artisan', function (): void {
    SupportersClub::factory()->create(['slug' => 'millbrook-away-days']);

    $this->artisan('matchday:sync-groups')
        ->expectsOutputToContain('Synced 2 supporters clubs')
        ->assertSuccessful();

    $this->analytics->assertGroupIdentified(Groups::SUPPORTERS_CLUB, 'harbour-city-exiles');
    $this->analytics->assertGroupIdentified(Groups::SUPPORTERS_CLUB, 'millbrook-away-days');
});

it('gives posthog-js the member\'s groups, and an empty object for others', function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);

    $member = $this->actingAs($this->member)->get(route('fixtures.index'))->getContent();
    $other = $this->actingAs(User::factory()->create())->get(route('fixtures.index'))->getContent();

    expect($member)->toContain('"groups":{"supporters_club":"harbour-city-exiles"}')
        ->and($other)->toContain('"groups":{}');
});

it('queues the grouped event for PostHog with its $groups', function (): void {
    Queue::fake();
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
    app()->forgetInstance(Analytics::class);

    $this->actingAs($this->member)->get(route('fixtures.show', Fixture::factory()->create()));

    Queue::assertPushed(CaptureAnalyticsEvent::class, fn (CaptureAnalyticsEvent $job): bool => $job->message['event'] === Events::FIXTURE_VIEWED
        && $job->message['groups'] === ['supporters_club' => 'harbour-city-exiles']);
});
