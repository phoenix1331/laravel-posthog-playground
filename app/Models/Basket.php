<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BasketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's in-progress selection for one fixture. Persisted rather than
 * held in the session payload so idle baskets can be queried; updated_at
 * doubles as last activity.
 */
#[Fillable(['user_id', 'fixture_id', 'ticket_allocation_id', 'quantity', 'travel_option_id', 'checkout_started_at', 'abandoned_at'])]
class Basket extends Model
{
    /** @use HasFactory<BasketFactory> */
    use HasFactory, HasUuids;

    public const int MAX_TICKETS = 6;

    /**
     * Minutes of inactivity after reaching checkout before a basket counts
     * as abandoned.
     */
    public const int ABANDON_AFTER_MINUTES = 30;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /**
     * @return BelongsTo<TicketAllocation, $this>
     */
    public function ticketAllocation(): BelongsTo
    {
        return $this->belongsTo(TicketAllocation::class);
    }

    /**
     * @return BelongsTo<TravelOption, $this>
     */
    public function travelOption(): BelongsTo
    {
        return $this->belongsTo(TravelOption::class);
    }

    /**
     * Reached checkout, idle past the threshold, not yet flagged. Purchased
     * baskets are deleted, so they never match.
     *
     * @param  Builder<Basket>  $query
     */
    #[Scope]
    protected function abandonable(Builder $query): void
    {
        $query->whereNotNull('checkout_started_at')
            ->whereNotNull('user_id')
            ->whereNull('abandoned_at')
            ->where('updated_at', '<=', now()->subMinutes(self::ABANDON_AFTER_MINUTES));
    }

    public function ticketTotalPence(): int
    {
        return $this->ticketAllocation->stand->price_pence * $this->quantity;
    }

    public function travelTotalPence(): int
    {
        if ($this->travelOption === null) {
            return 0;
        }

        return $this->travelOption->price_pence * $this->quantity;
    }

    public function totalPence(): int
    {
        return $this->ticketTotalPence() + $this->travelTotalPence();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'checkout_started_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }
}
