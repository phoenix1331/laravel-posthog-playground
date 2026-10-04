<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Basket;
use App\Models\TicketAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Basket>
 */
class BasketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_allocation_id' => TicketAllocation::factory(),
            'fixture_id' => fn (array $attributes) => TicketAllocation::findOrFail($attributes['ticket_allocation_id'])->fixture_id,
            'quantity' => 2,
            'travel_option_id' => null,
        ];
    }
}
