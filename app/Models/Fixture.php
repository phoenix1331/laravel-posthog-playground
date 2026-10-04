<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['competition_id', 'home_club_id', 'away_club_id', 'slug', 'kicks_off_at'])]
class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function homeClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'home_club_id');
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function awayClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'away_club_id');
    }

    /**
     * @return HasMany<TicketAllocation, $this>
     */
    public function ticketAllocations(): HasMany
    {
        return $this->hasMany(TicketAllocation::class);
    }

    /**
     * @return HasMany<TravelOption, $this>
     */
    public function travelOptions(): HasMany
    {
        return $this->hasMany(TravelOption::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function title(): string
    {
        return "{$this->homeClub->name} v {$this->awayClub->name}";
    }

    /**
     * Cheapest ticket still on sale, or null when every stand is sold out.
     * Expects ticketAllocations.stand to be eager loaded.
     */
    public function cheapestAvailablePricePence(): ?int
    {
        return $this->ticketAllocations
            ->reject->isSoldOut()
            ->min(fn (TicketAllocation $allocation) => $allocation->stand->price_pence);
    }

    public function isSellingFast(): bool
    {
        return $this->ticketAllocations->contains->isNearSellOut();
    }

    /**
     * @param  Builder<Fixture>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('kicks_off_at', '>', now())->orderBy('kicks_off_at');
    }

    protected function casts(): array
    {
        return [
            'kicks_off_at' => 'datetime',
        ];
    }
}
