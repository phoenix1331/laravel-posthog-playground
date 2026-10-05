<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

it('uses the in-memory store in tests and the database store by default', function (): void {
    expect(config('pennant.default'))->toBe('array')
        ->and(config('pennant.stores.database.driver'))->toBe('database');
});

it('resolves a feature per user and remembers the answer', function (): void {
    $calls = 0;
    Feature::define('probe-feature', function (User $user) use (&$calls): bool {
        $calls++;

        return $user->id % 2 === 0;
    });
    $user = User::factory()->create();

    $first = Feature::for($user)->active('probe-feature');
    $second = Feature::for($user)->active('probe-feature');

    expect($first)->toBe($second)->and($calls)->toBe(1);
});

it('stores resolved features in the features table when using the database store', function (): void {
    config(['pennant.default' => 'database']);
    Feature::define('probe-feature', fn (User $user): bool => true);
    $user = User::factory()->create();

    Feature::for($user)->active('probe-feature');

    $this->assertDatabaseHas('features', ['name' => 'probe-feature', 'value' => 'true']);
});
