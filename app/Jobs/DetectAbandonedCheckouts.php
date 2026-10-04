<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\CheckoutAbandoned;
use App\Models\Basket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled sweep that flags baskets left idle at checkout and fires
 * CheckoutAbandoned for each, exactly once.
 */
class DetectAbandonedCheckouts implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Basket::abandonable()
            ->with(['user.supportersClub', 'fixture.homeClub', 'fixture.awayClub', 'fixture.competition', 'ticketAllocation.stand', 'travelOption'])
            ->chunkById(100, function ($baskets): void {
                $baskets->each(fn (Basket $basket) => $this->abandon($basket));
            });
    }

    /**
     * The conditional update is the claim: if another run already flagged
     * this basket, no row changes and no event fires.
     */
    private function abandon(Basket $basket): void
    {
        $claimed = Basket::whereKey($basket->getKey())
            ->whereNull('abandoned_at')
            ->update(['abandoned_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        CheckoutAbandoned::dispatch(
            $basket,
            $basket->user,
            $basket->updated_at->toImmutable()->addMinutes(Basket::ABANDON_AFTER_MINUTES),
        );
    }
}
