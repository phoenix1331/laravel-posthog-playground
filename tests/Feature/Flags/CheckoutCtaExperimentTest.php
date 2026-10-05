<?php

declare(strict_types=1);

use App\Analytics\EventProperties;
use App\Analytics\Events;
use App\Analytics\Experiments;
use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\PostHogFlags;
use App\Analytics\Flags\Rollout;
use App\Checkout\FakePaymentGateway;
use App\Features\CheckoutCta;
use App\Jobs\DetectAbandonedCheckouts;
use App\Models\Basket;
use App\Models\Fixture;
use App\Models\Stand;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->user = User::factory()->create();
    $this->allocation = TicketAllocation::factory()->for(Stand::factory()->create(['price_pence' => 3_000]))->create();
});

function reachCheckout(TicketAllocation $allocation, User $user): TestResponse
{
    test()->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 2]);

    return test()->actingAs($user)->get(route('checkout.show'));
}

describe('variant assignment', function (): void {
    it('assigns exactly the variants PostHog would for a 50/50 multivariate flag', function (): void {
        Cache::put(CachedFlagDefinitions::KEY, [
            'flags' => [[
                'id' => 2,
                'key' => 'checkout-cta',
                'active' => true,
                'filters' => [
                    'groups' => [['properties' => [], 'rollout_percentage' => 100]],
                    'multivariate' => ['variants' => [
                        ['key' => 'control', 'rollout_percentage' => 50],
                        ['key' => 'test', 'rollout_percentage' => 50],
                    ]],
                ],
            ]],
            'group_type_mapping' => [],
            'cohorts' => [],
        ], 60);
        $posthog = new Client('phc_test', ['flag_definition_cache_provider' => new CachedFlagDefinitions(Cache::store(), 60)], new RecordingHttpClient, secretKey: 'phx_secret');

        $mismatches = collect(range(1, 400))
            ->map(fn (int $i): string => "supporter-{$i}")
            ->reject(fn (string $id): bool => $posthog->getAllFlags($id, onlyEvaluateLocally: true)['checkout-cta']
                === (new CheckoutCta)->resolve(new FlagScope($id)));

        expect($mismatches)->toBeEmpty();
    });

    it('splits visitors roughly evenly', function (): void {
        $tests = collect(range(1, 4000))->filter(fn (int $i): bool => Rollout::variant('checkout-cta', "supporter-{$i}", CheckoutCta::VARIANTS) === CheckoutCta::TEST);

        expect($tests->count() / 4000)->toBeGreaterThan(0.47)->toBeLessThan(0.53);
    });
});

describe('the checkout button', function (): void {
    it('renders the control label', function (): void {
        Feature::define('checkout-cta', CheckoutCta::CONTROL);

        reachCheckout($this->allocation, $this->user)->assertSee('Pay £60.00')->assertDontSee('Secure my seats');
    });

    it('renders the test label in the first response, with nothing to swap client-side', function (): void {
        Feature::define('checkout-cta', CheckoutCta::TEST);

        reachCheckout($this->allocation, $this->user)->assertSee('Secure my seats for £60.00')->assertDontSee('Pay £60.00');
    });

    it('falls back to the control copy for an unknown or disabled variant', function (mixed $variant): void {
        expect(CheckoutCta::label($variant, 6_000))->toBe('Pay £60.00');
    })->with([false, null, 'something-else']);

    it('shows a supporter the same button every time', function (): void {
        $first = reachCheckout($this->allocation, $this->user)->getContent();
        $second = $this->get(route('checkout.show'))->getContent();

        $label = fn (string $html): string => str_contains($html, 'Secure my seats') ? 'test' : 'control';
        expect($label($second))->toBe($label($first))
            ->and($label($first))->toBe((new CheckoutCta)->resolve(new FlagScope((string) $this->user->id)));
    });
});

describe('experiment attribution', function (): void {
    it('tags checkout events and the goal metric with the variant, but not earlier steps', function (): void {
        Feature::define('checkout-cta', CheckoutCta::TEST);
        $this->get(route('fixtures.show', $this->allocation->fixture));

        reachCheckout($this->allocation, $this->user);
        $this->post(route('checkout.store'), ['name_on_card' => 'Test', 'card_number' => FakePaymentGateway::SUCCESS_CARD]);

        foreach ([Events::CHECKOUT_STARTED, Events::TICKET_PURCHASED] as $event) {
            $this->analytics->assertCaptured($event, fn (array $call): bool => $call['properties']['$feature/checkout-cta'] === 'test');
        }
        foreach ([Events::FIXTURE_VIEWED, Events::SEAT_SELECTED] as $event) {
            $this->analytics->assertCaptured($event, fn (array $call): bool => ! array_key_exists('$feature/checkout-cta', $call['properties']));
        }
    });

    it('tags abandoned checkouts with the variant the supporter saw', function (): void {
        Feature::define('checkout-cta', CheckoutCta::CONTROL);
        $basket = Basket::factory()->create(['user_id' => $this->user->id, 'checkout_started_at' => now()->subHour()]);
        $basket->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        (new DetectAbandonedCheckouts)->handle();

        $this->analytics->assertCaptured(Events::CHECKOUT_ABANDONED, fn (array $call): bool => $call['properties']['$feature/checkout-cta'] === 'control');
    });

    it('records exposure on the checkout page, not for visitors who only browse', function (): void {
        config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test', 'pennant.default' => 'posthog']);
        app()->instance(PostHogFlags::class, new PostHogFlags(
            makeClient: fn (): Client => new Client('phc_test', [], new RecordingHttpClient(bodies: [
                '/flags/' => '{"flags": {"checkout-cta": {"key": "checkout-cta", "enabled": true, "variant": "test"}}}',
            ]), loadFeatureFlags: false),
            mode: PostHogFlags::REMOTE,
        ));
        Feature::forgetDrivers();

        $this->get(route('fixtures.show', Fixture::factory()->create()));
        $this->analytics->assertNotCaptured(Events::FEATURE_FLAG_CALLED);

        reachCheckout($this->allocation, $this->user)->assertSee('Secure my seats');

        $this->analytics->assertCaptured(Events::FEATURE_FLAG_CALLED, fn (array $call): bool => $call['distinct_id'] === (string) $this->user->id
            && $call['properties'] === ['$feature_flag' => 'checkout-cta', '$feature_flag_response' => 'test']);
    });

    it('lists which experiments tag which events', function (): void {
        expect(Experiments::tagging(Events::TICKET_PURCHASED))->toBe([CheckoutCta::class])
            ->and(Experiments::tagging(Events::FIXTURE_VIEWED))->toBe([]);
    });

    it('accepts $feature/ keys as event properties but still rejects unknown ones', function (): void {
        expect(EventProperties::make()->experiment('checkout-cta', 'test')->toArray())->toBe(['$feature/checkout-cta' => 'test']);
        expect(fn () => EventProperties::make()->with(['feature_checkout' => 'x']))->toThrow(InvalidArgumentException::class);
    });
});
