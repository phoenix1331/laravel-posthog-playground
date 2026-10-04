<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Club;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->unique()->city();
        $name = "{$city} ".fake()->randomElement(['Rovers', 'Athletic', 'United', 'Wanderers', 'Town']);

        return [
            'name' => $name,
            'short_name' => Str::upper(Str::substr(Str::slug($city, ''), 0, 3)),
            'slug' => Str::slug($name),
            'city' => $city,
            'stadium' => fake()->lastName().' Park',
            'capacity' => fake()->numberBetween(8_000, 45_000),
        ];
    }
}
