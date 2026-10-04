<?php

declare(strict_types=1);

use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the clubs, competitions and supporters clubs from the brief', function (): void {
    expect(Club::count())->toBe(6)
        ->and(Stand::count())->toBe(24)
        ->and(Competition::count())->toBe(2)
        ->and(SupportersClub::count())->toBe(4);
});

it('seeds twenty upcoming fixtures across both competitions', function (): void {
    expect(Fixture::upcoming()->count())->toBe(20)
        ->and(Fixture::distinct()->count('competition_id'))->toBe(2);
});

it('gives every fixture stock in each home stand and a coach for the away supporters', function (): void {
    Fixture::with('ticketAllocations', 'travelOptions', 'awayClub')->get()
        ->each(function (Fixture $fixture): void {
            expect($fixture->ticketAllocations)->toHaveCount(4)
                ->and($fixture->travelOptions->first()->departure_point)->toContain($fixture->awayClub->city);
        });
});

it('makes some fixtures close to selling out', function (): void {
    $nearSellOut = TicketAllocation::all()->filter->isNearSellOut();

    expect($nearSellOut->pluck('fixture_id')->unique())->toHaveCount(5)
        ->and($nearSellOut->filter->isSoldOut())->not->toBeEmpty();
});

it('seeds one admin and ten supporters with the documented password', function (): void {
    $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->sole();
    $supporters = User::where('is_admin', false)->get();

    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check(UserSeeder::PASSWORD, $admin->password))->toBeTrue()
        ->and($supporters)->toHaveCount(10)
        ->and($supporters->every(fn (User $user) => Hash::check(UserSeeder::PASSWORD, $user->password)))->toBeTrue();
});

it('spreads supporters across clubs of different sizes', function (): void {
    $sizes = SupportersClub::withCount('members')->pluck('members_count')->sort()->values()->all();

    expect($sizes)->toBe([1, 2, 3, 4]);
});

it('can run twice without duplicating data', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Club::count())->toBe(6)
        ->and(Fixture::count())->toBe(20)
        ->and(TicketAllocation::count())->toBe(80)
        ->and(User::count())->toBe(11);
});
