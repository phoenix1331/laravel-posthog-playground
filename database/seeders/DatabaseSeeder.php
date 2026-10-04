<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Every seeder upserts on a natural
     * key, so running this twice leaves the same data in place.
     */
    public function run(): void
    {
        $this->call([
            ClubSeeder::class,
            CompetitionSeeder::class,
            SupportersClubSeeder::class,
            UserSeeder::class,
            FixtureSeeder::class,
        ]);
    }
}
