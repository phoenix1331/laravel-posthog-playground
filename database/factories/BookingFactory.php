<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'MD-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'ticket_allocation_id' => TicketAllocation::factory(),
            'fixture_id' => fn (array $attributes) => TicketAllocation::findOrFail($attributes['ticket_allocation_id'])->fixture_id,
            'travel_option_id' => null,
            'quantity' => fake()->numberBetween(1, 4),
            'ticket_total_pence' => fn (array $attributes) => TicketAllocation::findOrFail($attributes['ticket_allocation_id'])->stand->price_pence * $attributes['quantity'],
            'travel_total_pence' => 0,
            'total_pence' => fn (array $attributes) => $attributes['ticket_total_pence'] + $attributes['travel_total_pence'],
            'purchased_at' => now(),
        ];
    }
}
