<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Fixture>
 */
class FixtureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kicksOffAt = fake()->dateTimeBetween('+3 days', '+60 days');

        return [
            'competition_id' => Competition::factory(),
            'home_club_id' => Club::factory(),
            'away_club_id' => Club::factory(),
            'slug' => Str::slug('fixture '.Str::random(8)),
            'kicks_off_at' => $kicksOffAt,
        ];
    }

    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'kicks_off_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
        ]);
    }
}
