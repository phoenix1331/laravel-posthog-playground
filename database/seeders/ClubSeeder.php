<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PriceBand;
use App\Models\Club;
use App\Models\Stand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Six invented clubs, each with the same four-stand layout priced from a
 * per-club base so bigger clubs charge more.
 */
class ClubSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, city: string, stadium: string, capacity: int, base_price_pence: int}>
     */
    public const array CLUBS = [
        'HBC' => ['name' => 'Harbour City', 'city' => 'Portwick', 'stadium' => 'The Quayside', 'capacity' => 32_000, 'base_price_pence' => 3_800],
        'EAS' => ['name' => 'Eastcliff United', 'city' => 'Eastcliff', 'stadium' => 'Cliff Top Stadium', 'capacity' => 27_000, 'base_price_pence' => 3_500],
        'NOR' => ['name' => 'Northgate Athletic', 'city' => 'Northgate', 'stadium' => 'Ironworks Park', 'capacity' => 24_000, 'base_price_pence' => 3_200],
        'MIL' => ['name' => 'Millbrook Rovers', 'city' => 'Millbrook', 'stadium' => 'Kiln Lane', 'capacity' => 18_500, 'base_price_pence' => 2_900],
        'RIV' => ['name' => 'Riverside Wanderers', 'city' => 'Riverside', 'stadium' => 'The Meadow', 'capacity' => 14_000, 'base_price_pence' => 2_600],
        'KIN' => ['name' => 'Kingsmoor Town', 'city' => 'Kingsmoor', 'stadium' => 'Crown Road', 'capacity' => 11_500, 'base_price_pence' => 2_200],
    ];

    /**
     * Stand layout shared by every ground: share of stadium capacity and
     * price multiplier against the club's base price.
     *
     * @var array<string, array{band: PriceBand, share: float, multiplier: float, away_end: bool}>
     */
    public const array STANDS = [
        'Main Stand' => ['band' => PriceBand::Premium, 'share' => 0.30, 'multiplier' => 1.6, 'away_end' => false],
        'North Stand' => ['band' => PriceBand::Standard, 'share' => 0.30, 'multiplier' => 1.0, 'away_end' => false],
        'Family Stand' => ['band' => PriceBand::Value, 'share' => 0.30, 'multiplier' => 0.7, 'away_end' => false],
        'Away End' => ['band' => PriceBand::Standard, 'share' => 0.10, 'multiplier' => 0.9, 'away_end' => true],
    ];

    public function run(): void
    {
        foreach (self::CLUBS as $shortName => $club) {
            $model = Club::updateOrCreate(
                ['slug' => Str::slug($club['name'])],
                [
                    'name' => $club['name'],
                    'short_name' => $shortName,
                    'city' => $club['city'],
                    'stadium' => $club['stadium'],
                    'capacity' => $club['capacity'],
                ],
            );

            foreach (self::STANDS as $standName => $stand) {
                Stand::updateOrCreate(
                    ['club_id' => $model->id, 'name' => $standName],
                    [
                        'is_away_end' => $stand['away_end'],
                        'price_band' => $stand['band'],
                        'price_pence' => self::roundToFiftyPence($club['base_price_pence'] * $stand['multiplier']),
                    ],
                );
            }
        }
    }

    private static function roundToFiftyPence(float $pence): int
    {
        return (int) (round($pence / 50) * 50);
    }
}
