<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Club;
use App\Models\SupportersClub;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportersClub>
 */
class SupportersClubFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city().' Supporters Club';

        return [
            'club_id' => Club::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
