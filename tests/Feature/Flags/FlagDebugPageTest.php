<?php

declare(strict_types=1);

use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagClientFactory;
use App\Analytics\Flags\FlagEvaluationBenchmark;
use App\Analytics\Flags\FlagScope;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

const DEBUG_REMOTE_FLAGS = '{"flags": {"new-seat-picker": {"key": "new-seat-picker", "enabled": true, "variant": null}}}';

/**
 * A client factory whose clients talk to recorded HTTP layers instead of PostHog.
 */
function recordedClients(RecordingHttpClient $localHttp, RecordingHttpClient $remoteHttp): FlagClientFactory
{
    $factory = new class(Cache::store(), $localHttp, $remoteHttp) extends FlagClientFactory
    {
        public function __construct(
            private readonly Repository $store,
            private readonly RecordingHttpClient $localHttp,
            private readonly RecordingHttpClient $remoteHttp,
        ) {
            parent::__construct($store);
        }

        public function local(): Client
        {
            return new Client('phc_test', ['flag_definition_cache_provider' => new CachedFlagDefinitions($this->store, 60)], $this->localHttp, secretKey: 'phx_secret');
        }

        public function remote(): Client
        {
            return new Client('phc_test', [], $this->remoteHttp, loadFeatureFlags: false);
        }
    };

    app()->instance(FlagClientFactory::class, $factory);

    return $factory;
}

function everyoneDefinitions(): string
{
    return json_encode([
        'flags' => [['id' => 1, 'key' => 'new-seat-picker', 'active' => true, 'filters' => ['groups' => [['properties' => [], 'rollout_percentage' => 100]]]]],
        'group_type_mapping' => [],
        'cohorts' => [],
    ]);
}

beforeEach(function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test', 'posthog.secret_key' => 'phx_secret']);
    $this->admin = User::factory()->admin()->create();
    $this->localHttp = new RecordingHttpClient(bodies: ['/flags/definitions' => everyoneDefinitions()]);
    $this->remoteHttp = new RecordingHttpClient(bodies: ['/flags/' => DEBUG_REMOTE_FLAGS]);
    recordedClients($this->localHttp, $this->remoteHttp);
});

it('is for admins only', function (): void {
    $this->get(route('debug.flags'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('debug.flags'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('debug.flags'))->assertOk();
});

it('compares local and remote side by side with timings and agreement', function (): void {
    $this->actingAs($this->admin)
        ->get(route('debug.flags', ['samples' => 5]))
        ->assertOk()
        ->assertSee(['data-mode="local"', 'data-mode="remote"'], false)
        ->assertSee(['Client setup', 'Per visitor', 'Total for 5 visitors'])
        ->assertSee('debug-sample-4')
        ->assertSee('{"new-seat-picker":true}')
        ->assertSee('yes')
        ->assertDontSee('>no<', false);
});

it('pays one /flags call per visitor remotely and none locally once definitions are cached', function (): void {
    $result = app(FlagEvaluationBenchmark::class)->run(array_map(fn (int $i) => new FlagScope("visitor-{$i}"), range(1, 6)));

    expect($this->remoteHttp->requestsTo('/flags/?v=2'))->toHaveCount(6)
        ->and($this->localHttp->requestsTo('/flags/definitions'))->toHaveCount(1)
        ->and($this->localHttp->requestsTo('/flags/?v=2'))->toHaveCount(0)
        ->and($result['local']['timings'])->toHaveCount(6)
        ->and($result['remote']['timings'])->toHaveCount(6)
        ->and($result['definitions']['cached'])->toBeFalse();

    app(FlagEvaluationBenchmark::class)->run([new FlagScope('visitor-7')]);

    expect($this->localHttp->requestsTo('/flags/definitions'))->toHaveCount(1);
});

it('flags disagreement between the modes', function (): void {
    recordedClients($this->localHttp, new RecordingHttpClient(bodies: ['/flags/' => '{"flags": {"new-seat-picker": {"key": "new-seat-picker", "enabled": false, "variant": null}}}']));

    $result = app(FlagEvaluationBenchmark::class)->run([new FlagScope('visitor-1')]);

    expect($result['agreement'])->toBe(['visitor-1' => false]);
});

it('caps the sample size', function (): void {
    $this->actingAs($this->admin)->get(route('debug.flags', ['samples' => 500]))->assertSee('Total for 25 visitors');
});

it('explains local evaluation needs the secret key', function (): void {
    config(['posthog.secret_key' => null]);

    $this->actingAs($this->admin)->get(route('debug.flags'))
        ->assertSee('Unavailable: set')
        ->assertSee('POSTHOG_PERSONAL_API_KEY');
});

it('shows fallback values without a PostHog token', function (): void {
    config(['posthog.project_token' => null]);

    $this->actingAs($this->admin)->get(route('debug.flags'))
        ->assertSee('Not sending to PostHog')
        ->assertDontSee('data-mode="remote"', false);

    expect($this->remoteHttp->requests)->toBeEmpty();
});

it('clears the definitions cache on request', function (): void {
    Cache::put(CachedFlagDefinitions::KEY, ['flags' => []], 60);

    $this->actingAs($this->admin)->post(route('debug.flags.clear'))->assertRedirect(route('debug.flags'));

    expect(Cache::has(CachedFlagDefinitions::KEY))->toBeFalse();
});

it('lists discovered class features among the fallbacks', function (): void {
    config(['posthog.project_token' => null]);

    $this->actingAs($this->admin)->get(route('debug.flags'))->assertSee('new-seat-picker');
});
