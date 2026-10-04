<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TravelOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporters' coach to an away fixture.
 */
#[Fillable(['fixture_id', 'departure_point', 'departs_at', 'price_pence', 'capacity', 'booked'])]
class TravelOption extends Model
{
    /** @use HasFactory<TravelOptionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->booked);
    }

    protected function casts(): array
    {
        return [
            'departs_at' => 'datetime',
            'price_pence' => 'integer',
            'capacity' => 'integer',
            'booked' => 'integer',
        ];
    }
}
