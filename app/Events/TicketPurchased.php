<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
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

    public readonly CarbonImmutable $occurredAt;

    public function __construct(public readonly Booking $booking)
    {
        $this->occurredAt = $booking->purchased_at->toImmutable();
    }
}
