<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'user_id',
    'fixture_id',
    'ticket_allocation_id',
    'travel_option_id',
    'quantity',
    'ticket_total_pence',
    'travel_total_pence',
    'total_pence',
    'purchased_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

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

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'ticket_total_pence' => 'integer',
            'travel_total_pence' => 'integer',
            'total_pence' => 'integer',
            'purchased_at' => 'datetime',
        ];
    }
}
