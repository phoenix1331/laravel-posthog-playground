<?php

declare(strict_types=1);

namespace App\Basket;

use App\Models\Basket;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The visitor's basket, tracked by ID in the session. One fixture per
 * basket: selecting seats for a different fixture starts again.
 */
class CurrentBasket
{
    private const string SESSION_KEY = 'basket_id';

    public function __construct(private readonly Session $session) {}

    public function get(): ?Basket
    {
        $id = $this->session->get(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        return Basket::with(['fixture.homeClub', 'fixture.awayClub', 'fixture.competition', 'ticketAllocation.stand', 'travelOption'])
            ->find($id);
    }

    public function selectSeats(TicketAllocation $allocation, int $quantity): Basket
    {
        $basket = $this->get();

        if ($basket === null || $basket->fixture_id !== $allocation->fixture_id) {
            $basket = new Basket(['fixture_id' => $allocation->fixture_id]);
        }

        $basket->fill([
            'ticket_allocation_id' => $allocation->id,
            'quantity' => $quantity,
            'travel_option_id' => $this->keepTravelIfItFits($basket, $quantity),
        ])->save();

        $this->session->put(self::SESSION_KEY, $basket->id);

        // Relations loaded before the change would describe the old selection
        return $basket->unsetRelations();
    }

    public function addTravel(Basket $basket, TravelOption $travelOption): Basket
    {
        $basket->travelOption()->associate($travelOption)->save();

        return $basket;
    }

    public function removeTravel(Basket $basket): Basket
    {
        $basket->travelOption()->dissociate()->save();

        return $basket;
    }

    /**
     * Marks the basket as having reached checkout. Returns true only the
     * first time, so reloading the checkout page is not a new start.
     */
    public function startCheckout(Basket $basket, User $user): bool
    {
        if ($basket->checkout_started_at !== null) {
            return false;
        }

        $basket->forceFill(['checkout_started_at' => now(), 'user_id' => $user->id])->save();

        return true;
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * Changing the ticket count drops a coach booking that no longer has
     * enough seats for everyone.
     */
    private function keepTravelIfItFits(Basket $basket, int $quantity): ?int
    {
        if ($basket->travelOption === null) {
            return null;
        }

        return $basket->travelOption->remaining() >= $quantity ? $basket->travel_option_id : null;
    }
}
