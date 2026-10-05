<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Analytics\Analytics;
use App\Analytics\DistinctId;
use App\Analytics\EventProperties;
use App\Analytics\Events;
use App\Analytics\Groups;
use App\Analytics\PersonProperties;
use App\Events\CheckoutAbandoned;
use App\Events\CheckoutStarted;
use App\Events\FixtureViewed;
use App\Events\SeatSelected;
use App\Events\TicketPurchased;
use App\Events\TravelAdded;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Events\Dispatcher;

/**
 * The translation table from domain events to analytics events. This is the
 * only place that knows which business moment becomes which analytics
 * event; business code just fires its events.
 *
 * Runs synchronously: Analytics implementations queue their own delivery,
 * and the distinct ID has to be read while the request's session exists.
 *
 * Methods are named on* rather than handle* so Laravel's listener discovery
 * does not register them a second time alongside subscribe().
 */
class AnalyticsEventSubscriber
{
    public function __construct(
        private readonly Analytics $analytics,
        private readonly DistinctId $distinctId,
    ) {}

    public function onFixtureViewed(FixtureViewed $event): void
    {
        $this->capture($event->user, Events::FIXTURE_VIEWED, EventProperties::make($event->occurredAt)
            ->fixture($event->fixture), $event);
    }

    public function onSeatSelected(SeatSelected $event): void
    {
        $basket = $event->basket;

        $this->capture($event->user, Events::SEAT_SELECTED, EventProperties::make($event->occurredAt)
            ->fixture($basket->fixture)
            ->seats($basket->ticketAllocation, $basket->quantity), $event);
    }

    public function onTravelAdded(TravelAdded $event): void
    {
        $this->capture($event->user, Events::TRAVEL_ADDED, EventProperties::make($event->occurredAt)
            ->basket($event->basket), $event);
    }

    public function onCheckoutStarted(CheckoutStarted $event): void
    {
        $this->capture($event->user, Events::CHECKOUT_STARTED, EventProperties::make($event->occurredAt)
            ->basket($event->basket), $event);
    }

    public function onTicketPurchased(TicketPurchased $event): void
    {
        $this->capture($event->booking->user, Events::TICKET_PURCHASED, EventProperties::make($event->occurredAt)
            ->booking($event->booking), $event);
    }

    public function onCheckoutAbandoned(CheckoutAbandoned $event): void
    {
        $this->capture($event->user, Events::CHECKOUT_ABANDONED, EventProperties::make($event->occurredAt)
            ->basket($event->basket), $event);
    }

    /**
     * Identity stitching. Before login the visitor is an anonymous ID; after
     * it they are their user ID. Aliasing tells PostHog both are one person,
     * so the browsing that led to a purchase stays attached to the buyer.
     *
     * The supporter's club is refreshed as a group at the same time, so its
     * name and member count stay current in PostHog.
     *
     * Fires for password logins, Auth::login() after registration and
     * remember-me logins alike. When the anonymous ID already is the user ID
     * (the browser identified first) there is nothing to merge.
     */
    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $userId = DistinctId::forUser($event->user);
        $anonymousId = $this->distinctId->anonymous();

        if ($anonymousId !== $userId) {
            $this->analytics->alias($userId, $anonymousId);
        }

        $this->analytics->identify($userId, PersonProperties::for($event->user));

        $supportersClub = $event->user->supportersClub;

        if ($supportersClub !== null) {
            $this->analytics->groupIdentify(Groups::SUPPORTERS_CLUB, $supportersClub->slug, Groups::properties($supportersClub));
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            FixtureViewed::class => 'onFixtureViewed',
            SeatSelected::class => 'onSeatSelected',
            TravelAdded::class => 'onTravelAdded',
            CheckoutStarted::class => 'onCheckoutStarted',
            TicketPurchased::class => 'onTicketPurchased',
            CheckoutAbandoned::class => 'onCheckoutAbandoned',
            Login::class => 'onLogin',
        ];
    }

    private function capture(?User $user, string $name, EventProperties $properties, object $event): void
    {
        $this->analytics->capture(
            distinctId: $this->distinctId->for($user),
            event: $name,
            properties: $properties->supportersClub($user?->supportersClub)->toArray(),
            groups: Groups::for($user),
            timestamp: $event->occurredAt,
        );
    }
}
