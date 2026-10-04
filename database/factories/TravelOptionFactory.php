<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\TravelOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelOption>
 */
class TravelOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'departure_point' => fake()->city().' Coach Station',
            'departs_at' => fake()->dateTimeBetween('+2 days', '+59 days'),
            'price_pence' => 1_800,
            'capacity' => 50,
            'booked' => 0,
        ];
    }
}
