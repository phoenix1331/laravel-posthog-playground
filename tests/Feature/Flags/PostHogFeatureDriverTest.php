<?php

declare(strict_types=1);

use App\Analytics\DistinctId;
use App\Analytics\Events;
use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\PostHogFlags;
use App\Models\SupportersClub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use PostHog\Client;
use Tests\Support\RecordingHttpClient;

uses(RefreshDatabase::class);

const REMOTE_FLAGS = '{"flags": {"new-seat-picker": {"key": "new-seat-picker", "enabled": true, "variant": null}, "checkout-cta": {"key": "checkout-cta", "enabled": true, "variant": "b"}, "dark-mode": {"key": "dark-mode", "enabled": false, "variant": null}}}';

/**
 * Local definitions for a flag on for everyone, in the shape PostHog's
 * /flags/definitions endpoint returns.
 *
 * @return array<string, mixed>
 */
function localDefinitions(): array
{
    return [
        'flags' => [[
            'id' => 1,
            'key' => 'new-seat-picker',
            'active' => true,
            'filters' => ['groups' => [['properties' => [], 'rollout_percentage' => 100]]],
        ]],
        'group_type_mapping' => [],
        'cohorts' => [],
    ];
}

/**
 * Swap in PostHogFlags built on a real SDK client whose HTTP layer is recorded.
 */
function postHogFlagsUsing(RecordingHttpClient $http, string $mode = PostHogFlags::REMOTE): PostHogFlags
{
    $flags = new PostHogFlags(
        makeClient: fn (): Client => new Client(
            apiKey: 'phc_test',
            options: $mode === PostHogFlags::LOCAL
                ? ['flag_definition_cache_provider' => new CachedFlagDefinitions(Cache::store(), 60)]
                : [],
            httpClient: $http,
            loadFeatureFlags: $mode === PostHogFlags::LOCAL,
            secretKey: $mode === PostHogFlags::LOCAL ? 'phx_secret' : null,
        ),
        mode: $mode,
    );

    app()->instance(PostHogFlags::class, $flags);
    Feature::forgetDrivers();

    return $flags;
}

beforeEach(function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test', 'pennant.default' => 'posthog']);
    $this->analytics = fakeAnalytics();
    Feature::forgetDrivers();
});

describe('values from PostHog', function (): void {
    beforeEach(function (): void {
        $this->http = new RecordingHttpClient(bodies: ['/flags/' => REMOTE_FLAGS]);
        postHogFlagsUsing($this->http);
    });

    it('answers Feature::active() and Feature::value() from PostHog flags', function (): void {
        $scope = new FlagScope('visitor-1');

        expect(Feature::for($scope)->active('new-seat-picker'))->toBeTrue()
            ->and(Feature::for($scope)->active('dark-mode'))->toBeFalse()
            ->and(Feature::for($scope)->value('checkout-cta'))->toBe('b');
    });

    it('asks PostHog once per visitor per request, however many flags are read', function (): void {
        $scope = new FlagScope('visitor-1');

        Feature::for($scope)->active('new-seat-picker');
        Feature::for($scope)->value('checkout-cta');
        Feature::for($scope)->active('dark-mode');

        expect($this->http->requestsTo('/flags/'))->toHaveCount(1)
            ->and($this->http->requestsTo('/flags/')[0]['payload']['distinct_id'])->toBe('visitor-1');
    });

    it('sends groups and person properties so PostHog can match release conditions', function (): void {
        $user = User::factory()->inSupportersClub(SupportersClub::factory()->create(['slug' => 'harbour-city-exiles']))->create();

        Feature::for($user)->active('new-seat-picker');

        $payload = $this->http->requestsTo('/flags/')[0]['payload'];
        expect($payload['distinct_id'])->toBe((string) $user->id)
            ->and($payload['groups'])->toBe(['supporters_club' => 'harbour-city-exiles'])
            ->and($payload['person_properties']['supporters_club'])->toBe('harbour-city-exiles');
    });

    it('falls back to the app definition for flags PostHog does not know', function (): void {
        Feature::define('not-in-posthog', fn (FlagScope $scope): string => "fallback-for-{$scope->distinctId}");

        expect(Feature::for(new FlagScope('visitor-1'))->value('not-in-posthog'))->toBe('fallback-for-visitor-1')
            ->and(Feature::for(new FlagScope('visitor-1'))->active('never-defined'))->toBeFalse();
    });

    it('records exposure once per person and value through the queued analytics contract', function (): void {
        $scope = new FlagScope('visitor-1', ['supporters_club' => 'harbour-city-exiles']);

        Feature::for($scope)->value('checkout-cta');
        Feature::flushCache();
        Feature::for($scope)->value('checkout-cta');

        $this->analytics->assertCapturedTimes(Events::FEATURE_FLAG_CALLED, 1);
        $this->analytics->assertCaptured(Events::FEATURE_FLAG_CALLED, fn (array $call): bool => $call['distinct_id'] === 'visitor-1'
            && $call['properties'] === ['$feature_flag' => 'checkout-cta', '$feature_flag_response' => 'b']
            && $call['groups'] === ['supporters_club' => 'harbour-city-exiles']);
    });

    it('lists the flags PostHog knows alongside app fallbacks', function (): void {
        Feature::define('local-only', fn (): bool => true);

        expect(Feature::for(new FlagScope('visitor-1'))->all())
            ->toHaveKeys(['new-seat-picker', 'checkout-cta', 'dark-mode', 'local-only']);
    });

    it('refuses to write flag values, because PostHog owns rollout', function (Closure $write): void {
        expect($write)->toThrow(LogicException::class, 'managed in PostHog');
    })->with([
        'activate' => [fn () => Feature::for(new FlagScope('visitor-1'))->activate('new-seat-picker')],
        'activate for everyone' => [fn () => Feature::activateForEveryone('new-seat-picker')],
        'forget' => [fn () => Feature::for(new FlagScope('visitor-1'))->forget('new-seat-picker')],
        'purge' => [fn () => Feature::purge()],
    ]);
});

it('scopes flags to the current visitor\'s distinct id by default', function (): void {
    $http = new RecordingHttpClient(bodies: ['/flags/' => REMOTE_FLAGS]);
    postHogFlagsUsing($http);
    $this->withSession([DistinctId::SESSION_KEY => 'anon-from-session']);
    $this->get('/up');

    Feature::active('new-seat-picker');

    expect($http->requestsTo('/flags/')[0]['payload']['distinct_id'])->toBe('anon-from-session');
});

it('uses app fallbacks and never calls PostHog without a token', function (): void {
    config(['posthog.project_token' => null]);
    $http = new RecordingHttpClient(bodies: ['/flags/' => REMOTE_FLAGS]);
    postHogFlagsUsing($http);
    Feature::define('new-seat-picker', fn (FlagScope $scope): bool => false);

    expect(Feature::for(new FlagScope('visitor-1'))->active('new-seat-picker'))->toBeFalse()
        ->and($http->requests)->toBeEmpty();
    $this->analytics->assertNothingCaptured();
});

describe('local evaluation', function (): void {
    it('evaluates from cached definitions with no network call', function (): void {
        Cache::put(CachedFlagDefinitions::KEY, localDefinitions(), 60);
        $http = new RecordingHttpClient;
        $flags = postHogFlagsUsing($http, PostHogFlags::LOCAL);

        expect(Feature::for(new FlagScope('visitor-1'))->active('new-seat-picker'))->toBeTrue()
            ->and($http->requests)->toBeEmpty()
            ->and($flags->timings()[0]['mode'])->toBe(PostHogFlags::LOCAL);
    });

    it('downloads definitions once and shares them through the cache', function (): void {
        $http = new RecordingHttpClient(bodies: ['/flags/definitions' => json_encode(localDefinitions())]);

        postHogFlagsUsing($http, PostHogFlags::LOCAL);
        Feature::for(new FlagScope('visitor-1'))->active('new-seat-picker');

        postHogFlagsUsing($http, PostHogFlags::LOCAL);
        Feature::for(new FlagScope('visitor-2'))->active('new-seat-picker');

        expect($http->requestsTo('/flags/definitions'))->toHaveCount(1)
            ->and(Cache::get(CachedFlagDefinitions::KEY)['flags'][0]['key'])->toBe('new-seat-picker');
    });
});

it('lets only one worker refresh expired definitions at a time', function (): void {
    $provider = new CachedFlagDefinitions(Cache::store(), 60);

    expect($provider->shouldFetchFlagDefinitions())->toBeTrue()
        ->and($provider->shouldFetchFlagDefinitions())->toBeFalse();

    $provider->onFlagDefinitionsReceived(localDefinitions());

    expect($provider->getFlagDefinitions())->toBe(localDefinitions())
        ->and($provider->shouldFetchFlagDefinitions())->toBeFalse();
});

it('keeps the request alive if PostHog flag evaluation fails', function (): void {
    $flags = new PostHogFlags(makeClient: fn () => throw new RuntimeException('PostHog down'), mode: PostHogFlags::REMOTE);

    expect($flags->evaluate(new FlagScope('visitor-1')))->toBe([]);
});
