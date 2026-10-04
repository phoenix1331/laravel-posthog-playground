<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SupportersClub;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One admin and ten supporters, all with the password "password" so the
 * README can document every login.
 */
class UserSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    public const string ADMIN_EMAIL = 'admin@matchday.test';

    /**
     * @var array<string, string|null> supporter name => supporters' club name
     */
    public const array SUPPORTERS = [
        'Alex Morgan' => 'Harbour City Exiles',
        'Priya Shah' => 'Harbour City Exiles',
        'Tom Fletcher' => 'Harbour City Exiles',
        'Jess Okafor' => 'Harbour City Exiles',
        'Sam Doyle' => 'Eastcliff Gulls Travel Club',
        'Chloe Bennett' => 'Eastcliff Gulls Travel Club',
        'Marcus Reid' => 'Eastcliff Gulls Travel Club',
        'Hannah Price' => 'Northgate Ironsides',
        'Owen Hughes' => 'Northgate Ironsides',
        'Leah Turner' => 'Millbrook Away Days',
    ];

    public function run(): void
    {
        $password = Hash::make(self::PASSWORD);

        $admin = User::updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            ['name' => 'Matchday Admin', 'password' => $password, 'email_verified_at' => now()],
        );
        $admin->forceFill(['is_admin' => true])->save();

        $supportersClubs = SupportersClub::pluck('id', 'name');

        foreach (self::SUPPORTERS as $name => $supportersClub) {
            User::updateOrCreate(
                ['email' => self::emailFor($name)],
                [
                    'name' => $name,
                    'password' => $password,
                    'email_verified_at' => now(),
                    'supporters_club_id' => $supportersClubs[$supportersClub] ?? null,
                ],
            );
        }
    }

    public static function emailFor(string $name): string
    {
        return Str::slug($name, '.').'@matchday.test';
    }
}
