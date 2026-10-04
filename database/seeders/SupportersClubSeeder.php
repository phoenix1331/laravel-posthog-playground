<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Club;
use App\Models\SupportersClub;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Four supporters' clubs used as PostHog groups. Membership sizes differ
 * (see UserSeeder) so group analytics has something to compare.
 */
class SupportersClubSeeder extends Seeder
{
    /**
     * @var array<string, string> supporters' club name => club short name
     */
    public const array SUPPORTERS_CLUBS = [
        'Harbour City Exiles' => 'HBC',
        'Eastcliff Gulls Travel Club' => 'EAS',
        'Northgate Ironsides' => 'NOR',
        'Millbrook Away Days' => 'MIL',
    ];

    public function run(): void
    {
        foreach (self::SUPPORTERS_CLUBS as $name => $shortName) {
            SupportersClub::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'club_id' => Club::where('short_name', $shortName)->value('id'),
                ],
            );
        }
    }
}
