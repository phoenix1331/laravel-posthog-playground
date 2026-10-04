<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Events\TicketPurchased;
use App\Models\Basket;
use App\Models\Booking;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a basket into a booking. Stock rows are locked for the whole
 * transaction so two supporters cannot buy the last seats twice.
 */
class PlaceBooking
{
    public function __construct(private readonly FakePaymentGateway $payments) {}

    /**
     * @throws CheckoutFailed
     */
    public function handle(Basket $basket, User $user, string $cardNumber): Booking
    {
        return DB::transaction(function () use ($basket, $user, $cardNumber): Booking {
            if ($basket->fixture->kicks_off_at->isPast()) {
                throw CheckoutFailed::kickedOff();
            }

            $allocation = TicketAllocation::lockForUpdate()->findOrFail($basket->ticket_allocation_id);

            if ($allocation->remaining() < $basket->quantity) {
                throw CheckoutFailed::ticketsGone();
            }

            $travelOption = $this->lockTravelOption($basket);

            $this->payments->charge($cardNumber, $basket->totalPence());

            $allocation->increment('sold', $basket->quantity);
            $travelOption?->increment('booked', $basket->quantity);

            $booking = Booking::create([
                'reference' => $this->newReference(),
                'user_id' => $user->id,
                'fixture_id' => $basket->fixture_id,
                'ticket_allocation_id' => $allocation->id,
                'travel_option_id' => $travelOption?->id,
                'quantity' => $basket->quantity,
                'ticket_total_pence' => $basket->ticketTotalPence(),
                'travel_total_pence' => $basket->travelTotalPence(),
                'total_pence' => $basket->totalPence(),
                'purchased_at' => now(),
            ]);

            $basket->delete();

            TicketPurchased::dispatch($booking);

            return $booking;
        });
    }

    private function lockTravelOption(Basket $basket): ?TravelOption
    {
        if ($basket->travel_option_id === null) {
            return null;
        }

        $travelOption = TravelOption::lockForUpdate()->findOrFail($basket->travel_option_id);

        if ($travelOption->remaining() < $basket->quantity) {
            throw CheckoutFailed::coachFull();
        }

        return $travelOption;
    }

    private function newReference(): string
    {
        do {
            $reference = 'MD-'.Str::upper(Str::random(8));
        } while (Booking::where('reference', $reference)->exists());

        return $reference;
    }
}
