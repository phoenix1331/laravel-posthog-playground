<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Competition;
use Illuminate\Database\Seeder;

class CompetitionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    public const array COMPETITIONS = [
        'premier-division' => 'Premier Division',
        'county-shield' => 'County Shield',
    ];

    public function run(): void
    {
        foreach (self::COMPETITIONS as $slug => $name) {
            Competition::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
