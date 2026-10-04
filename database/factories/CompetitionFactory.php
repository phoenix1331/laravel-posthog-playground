<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Northern League', 'Southern League', 'County Cup', 'Challenge Trophy']);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
