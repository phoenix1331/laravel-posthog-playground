<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceBand;
use App\Models\Club;
use App\Models\Stand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stand>
 */
class StandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name' => fake()->randomElement(['North', 'South', 'East', 'West']).' Stand',
            'is_away_end' => false,
            'price_band' => PriceBand::Standard,
            'price_pence' => 3_200,
        ];
    }

    public function awayEnd(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Away End',
            'is_away_end' => true,
            'price_band' => PriceBand::Value,
            'price_pence' => 2_500,
        ]);
    }

    public function band(PriceBand $band, int $pricePence): static
    {
        return $this->state(fn (array $attributes) => [
            'price_band' => $band,
            'price_pence' => $pricePence,
        ]);
    }
}
