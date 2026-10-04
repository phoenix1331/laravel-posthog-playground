<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ticket stock for one stand at one fixture. Seats are not modelled
 * individually; capacity and sold counts are enough for the funnel.
 */
#[Fillable(['fixture_id', 'stand_id', 'capacity', 'sold'])]
class TicketAllocation extends Model
{
    /** @use HasFactory<TicketAllocationFactory> */
    use HasFactory;

    /**
     * Share of capacity sold at which an allocation counts as nearly gone.
     */
    public const float NEAR_SELL_OUT_RATIO = 0.9;

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /**
     * @return BelongsTo<Stand, $this>
     */
    public function stand(): BelongsTo
    {
        return $this->belongsTo(Stand::class);
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->sold);
    }

    public function isSoldOut(): bool
    {
        return $this->remaining() === 0;
    }

    public function isNearSellOut(): bool
    {
        return $this->sold >= $this->capacity * self::NEAR_SELL_OUT_RATIO;
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'sold' => 'integer',
        ];
    }
}
