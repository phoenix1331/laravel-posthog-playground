<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A supporter completed checkout. Dispatched only once the booking
 * transaction commits, so listeners never see a rolled-back purchase.
 */
class TicketPurchased implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Booking $booking) {}
}
