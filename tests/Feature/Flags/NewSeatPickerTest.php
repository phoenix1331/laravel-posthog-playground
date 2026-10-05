<?php

declare(strict_types=1);

use App\Analytics\Events;
use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\Rollout;
use App\Features\NewSeatPicker;
use App\Models\Basket;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->fixture = Fixture::factory()->create();
    foreach (['North Stand', 'Main Stand', 'Family Stand'] as $name) {
        TicketAllocation::factory()->for($this->fixture)->for(Stand::factory()->create(['name' => $name]))->create();
    }
    $this->awayEnd = TicketAllocation::factory()->for($this->fixture)->for(Stand::factory()->awayEnd())->soldOut()->create();
});

describe('rollout fallback', function (): void {
    it('puts exactly the visitors PostHog would in a 20% rollout', function (): void {
        Cache::put(CachedFlagDefinitions::KEY, [
            'flags' => [[
                'id' => 1,
                'key' => 'new-seat-picker',
                'active' => true,
                'filters' => ['groups' => [['properties' => [], 'rollout_percentage' => NewSeatPicker::ROLLOUT_PERCENTAGE]]],
            ]],
            'group_type_mapping' => [],
            'cohorts' => [],
        ], 60);
        $posthog = new Client('phc_test', ['flag_definition_cache_provider' => new CachedFlagDefinitions(Cache::store(), 60)], new RecordingHttpClient, secretKey: 'phx_secret');

        $mismatches = collect(range(1, 400))
            ->map(fn (int $i): string => "visitor-{$i}")
            ->reject(fn (string $id): bool => ($posthog->getAllFlags($id, onlyEvaluateLocally: true)['new-seat-picker'] ?? false)
                === (new NewSeatPicker)->resolve(new FlagScope($id)));

        expect($mismatches)->toBeEmpty();
    });

    it('includes roughly a fifth of visitors and always the same ones', function (): void {
        $ids = collect(range(1, 4000))->map(fn (int $i): string => "visitor-{$i}");
        $included = $ids->filter(fn (string $id): bool => Rollout::includes('new-seat-picker', $id, 20));

        expect($included->count() / 4000)->toBeGreaterThan(0.18)->toBeLessThan(0.22)
            ->and($ids->filter(fn (string $id): bool => Rollout::includes('new-seat-picker', $id, 20))->all())->toBe($included->all());
    });

    it('includes nobody at 0% and everybody at 100%', function (): void {
        expect(Rollout::includes('new-seat-picker', 'visitor-1', 0))->toBeFalse()
            ->and(Rollout::includes('new-seat-picker', 'visitor-1', 100))->toBeTrue();
    });
});

it('shows the stadium map to visitors in the rollout', function (): void {
    Feature::define('new-seat-picker', true);

    $html = $this->get(route('fixtures.show', $this->fixture))
        ->assertOk()
        ->assertSee('data-seat-picker="map"', false)
        ->assertDontSee('data-seat-picker="list"', false)
        ->assertSee(['Pitch', 'North Stand', 'Main Stand', 'Family Stand', 'Away End'])
        ->getContent();

    // Stands are placed around the pitch by grid position, not markup order
    expect($html)->toMatch('/class="col-start-2 row-start-1[^"]*">\s*<input[^>]+>\s*<span[^>]*>\s*<span class="font-medium">North Stand/')
        ->and($html)->toMatch('/value="'.$this->awayEnd->id.'"[^>]*disabled/');
});

it('shows the original list to everyone else', function (): void {
    Feature::define('new-seat-picker', false);

    $this->get(route('fixtures.show', $this->fixture))
        ->assertSee('data-seat-picker="list"', false)
        ->assertDontSee('data-seat-picker="map"', false);
});

it('books seats from the map through the same route and funnel event', function (): void {
    Feature::define('new-seat-picker', true);
    $north = $this->fixture->ticketAllocations()->whereHas('stand', fn ($query) => $query->where('name', 'North Stand'))->sole();

    $this->post(route('seats.store', $this->fixture), ['ticket_allocation_id' => $north->id, 'quantity' => 3])
        ->assertRedirect(route('basket.show'));

    expect(Basket::sole()->quantity)->toBe(3);
    $this->analytics->assertCaptured(Events::SEAT_SELECTED, fn (array $call): bool => $call['properties']['stand'] === 'North Stand');
});

it('decides the picker through Pennant with the visitor as scope', function (): void {
    $this->get(route('fixtures.show', $this->fixture));
    $visitor = $this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];

    expect(Feature::for(new FlagScope($visitor))->active(NewSeatPicker::class))
        ->toBe(Rollout::includes('new-seat-picker', $visitor, NewSeatPicker::ROLLOUT_PERCENTAGE));
});
