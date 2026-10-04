<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Twenty upcoming fixtures: a full Premier Division round robin over five
 * Saturdays plus five midweek County Shield ties. Stock levels are derived
 * from a hash of fixture and stand so every run produces the same picture,
 * with a handful of big matches close to selling out.
 */
class FixtureSeeder extends Seeder
{
    /**
     * home, away, competition slug, days after the next Saturday, kick-off time, near sell-out
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5: bool}>
     */
    public const array FIXTURES = [
        ['HBC', 'KIN', 'premier-division', 0, '15:00', false],
        ['EAS', 'RIV', 'premier-division', 0, '15:00', false],
        ['NOR', 'MIL', 'premier-division', 0, '17:30', false],
        ['KIN', 'HBC', 'county-shield', 3, '19:45', true],
        ['KIN', 'MIL', 'premier-division', 7, '15:00', false],
        ['RIV', 'NOR', 'premier-division', 7, '15:00', false],
        ['HBC', 'EAS', 'premier-division', 7, '17:30', true],
        ['RIV', 'EAS', 'county-shield', 10, '19:45', false],
        ['EAS', 'KIN', 'premier-division', 14, '15:00', false],
        ['NOR', 'HBC', 'premier-division', 14, '15:00', true],
        ['MIL', 'RIV', 'premier-division', 14, '17:30', false],
        ['MIL', 'NOR', 'county-shield', 17, '19:45', false],
        ['KIN', 'RIV', 'premier-division', 21, '15:00', false],
        ['HBC', 'MIL', 'premier-division', 21, '15:00', false],
        ['EAS', 'NOR', 'premier-division', 21, '17:30', true],
        ['EAS', 'HBC', 'county-shield', 24, '19:45', true],
        ['NOR', 'KIN', 'premier-division', 28, '15:00', false],
        ['RIV', 'HBC', 'premier-division', 28, '15:00', false],
        ['MIL', 'EAS', 'premier-division', 28, '17:30', false],
        ['NOR', 'RIV', 'county-shield', 31, '19:45', false],
    ];

    private const int COACH_CAPACITY = 53;

    private const int COACH_LEAD_HOURS = 4;

    public function run(): void
    {
        $clubs = Club::with('stands', 'supportersClubs')->get()->keyBy('short_name');
        $competitions = Competition::pluck('id', 'slug');
        $firstMatchday = CarbonImmutable::now()->next(CarbonImmutable::SATURDAY);

        foreach (self::FIXTURES as [$home, $away, $competition, $dayOffset, $time, $nearSellOut]) {
            $homeClub = $clubs[$home];
            $awayClub = $clubs[$away];

            $fixture = Fixture::updateOrCreate(
                ['slug' => Str::slug("{$homeClub->name} v {$awayClub->name} {$competition}")],
                [
                    'competition_id' => $competitions[$competition],
                    'home_club_id' => $homeClub->id,
                    'away_club_id' => $awayClub->id,
                    'kicks_off_at' => $firstMatchday->addDays($dayOffset)->setTimeFromTimeString($time),
                ],
            );

            $this->seedAllocations($fixture, $homeClub, $nearSellOut);
            $this->seedTravel($fixture, $awayClub);
        }
    }

    private function seedAllocations(Fixture $fixture, Club $homeClub, bool $nearSellOut): void
    {
        foreach ($homeClub->stands as $stand) {
            $capacity = (int) round($homeClub->capacity * ClubSeeder::STANDS[$stand->name]['share']);
            $soldRatio = $this->soldRatio($fixture->slug, $stand->name, $stand->is_away_end, $nearSellOut);

            TicketAllocation::updateOrCreate(
                ['fixture_id' => $fixture->id, 'stand_id' => $stand->id],
                ['capacity' => $capacity, 'sold' => (int) floor($capacity * $soldRatio)],
            );
        }
    }

    /**
     * Big matches: away end gone, everything else nearly gone. Otherwise a
     * stable 15-69% sold, varied per fixture and stand.
     */
    private function soldRatio(string $fixtureSlug, string $standName, bool $isAwayEnd, bool $nearSellOut): float
    {
        if ($nearSellOut && $isAwayEnd) {
            return 1.0;
        }

        if ($nearSellOut) {
            return 0.94;
        }

        return 0.15 + (crc32("{$fixtureSlug}|{$standName}") % 55) / 100;
    }

    /**
     * One coach from the away club's city, plus a second run by the away
     * club's supporters' club when it has one.
     */
    private function seedTravel(Fixture $fixture, Club $awayClub): void
    {
        $departsAt = $fixture->kicks_off_at->subHours(self::COACH_LEAD_HOURS);

        /** @var Collection<int, array{departure_point: string, departs_at: \DateTimeInterface, price_pence: int}> $coaches */
        $coaches = collect([[
            'departure_point' => "{$awayClub->city} Coach Station",
            'departs_at' => $departsAt,
            'price_pence' => 1_500 + (crc32($fixture->slug) % 11) * 100,
        ]]);

        $supportersClub = $awayClub->supportersClubs->first();

        if ($supportersClub !== null) {
            $coaches->push([
                'departure_point' => "{$supportersClub->name} coach, {$awayClub->stadium}",
                'departs_at' => $departsAt->subMinutes(30),
                'price_pence' => 1_200,
            ]);
        }

        foreach ($coaches as $coach) {
            TravelOption::updateOrCreate(
                ['fixture_id' => $fixture->id, 'departure_point' => $coach['departure_point']],
                [
                    'departs_at' => $coach['departs_at'],
                    'price_pence' => $coach['price_pence'],
                    'capacity' => self::COACH_CAPACITY,
                    'booked' => crc32($coach['departure_point'].$fixture->slug) % 40,
                ],
            );
        }
    }
}
