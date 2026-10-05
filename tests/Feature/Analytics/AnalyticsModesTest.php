<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\Events;
use App\Analytics\NullAnalytics;
use App\Analytics\PostHogAnalytics;
use App\Checkout\FakePaymentGateway;
use App\Jobs\CaptureAnalyticsEvent;
use App\Models\Booking;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The whole supporter journey through real routes, with whichever
 * Analytics implementation the container resolves.
 */
function completeJourney(): void
{
    $allocation = TicketAllocation::factory()->create();
    $coach = TravelOption::factory()->for($allocation->fixture)->create();
    $user = User::factory()->create();

    test()->get(route('fixtures.index'))->assertOk();
    test()->get(route('fixtures.show', $allocation->fixture))->assertOk();
    test()->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 2])->assertRedirect();
    test()->post(route('travel.store'), ['travel_option_id' => $coach->id])->assertRedirect();
    test()->get(route('basket.show'))->assertOk();
    test()->actingAs($user)->get(route('checkout.show'))->assertOk();
    test()->post(route('checkout.store'), ['name_on_card' => 'Test', 'card_number' => FakePaymentGateway::SUCCESS_CARD])->assertRedirect();
}

function resolveAnalyticsFresh(): Analytics
{
    app()->forgetInstance(Analytics::class);

    return app(Analytics::class);
}

it('runs the whole journey with no PostHog token and queues nothing', function (): void {
    Queue::fake();
    config(['posthog.disabled' => false, 'posthog.project_token' => null]);

    expect(resolveAnalyticsFresh()->inner())->toBeInstanceOf(NullAnalytics::class);

    completeJourney();

    expect(Booking::count())->toBe(1);
    Queue::assertNotPushed(CaptureAnalyticsEvent::class);
});

it('runs the whole journey with the kill switch on and queues nothing', function (): void {
    Queue::fake();
    config(['posthog.disabled' => true, 'posthog.project_token' => 'phc_test']);
    resolveAnalyticsFresh();

    completeJourney();

    Queue::assertNotPushed(CaptureAnalyticsEvent::class);
});

it('queues every funnel event for PostHog when a token is set, all on the analytics queue', function (): void {
    Queue::fake();
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);

    expect(resolveAnalyticsFresh()->inner())->toBeInstanceOf(PostHogAnalytics::class);

    completeJourney();

    $events = Queue::pushed(CaptureAnalyticsEvent::class)->map(fn (CaptureAnalyticsEvent $job) => $job->message['event']);
    expect($events->all())->toBe(Events::funnel());
    expect(Queue::pushed(CaptureAnalyticsEvent::class)->pluck('queue')->unique()->all())->toBe(['analytics']);
});
