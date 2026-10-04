<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceBand;
use Database\Factories\StandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['club_id', 'name', 'is_away_end', 'price_band', 'price_pence'])]
class Stand extends Model
{
    /** @use HasFactory<StandFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @return HasMany<TicketAllocation, $this>
     */
    public function ticketAllocations(): HasMany
    {
        return $this->hasMany(TicketAllocation::class);
    }

    protected function casts(): array
    {
        return [
            'is_away_end' => 'boolean',
            'price_band' => PriceBand::class,
            'price_pence' => 'integer',
        ];
    }
}
