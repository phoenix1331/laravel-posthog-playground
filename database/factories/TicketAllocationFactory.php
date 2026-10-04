<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketAllocation>
 */
class TicketAllocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'stand_id' => Stand::factory(),
            'capacity' => 1_000,
            'sold' => 0,
        ];
    }

    public function nearSellOut(): static
    {
        return $this->state([
            'sold' => fn (array $attributes) => (int) floor($attributes['capacity'] * 0.95),
        ]);
    }

    public function soldOut(): static
    {
        return $this->state([
            'sold' => fn (array $attributes) => $attributes['capacity'],
        ]);
    }
}
