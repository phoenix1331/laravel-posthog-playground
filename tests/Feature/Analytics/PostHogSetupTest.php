<?php

declare(strict_types=1);

use App\Console\Commands\SetUpPostHog;
use App\Enums\ExitReason;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const API = 'https://eu.posthog.com/api/projects/42/';

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 15:00:00');
    config(['posthog.secret_key' => 'phx_test', 'posthog.surveys.checkout_exit' => null]);

    // Never touch the real .env
    $this->envDir = sys_get_temp_dir().'/posthog-setup-'.uniqid();
    mkdir($this->envDir);
    file_put_contents($this->envDir.'/.env', "APP_NAME=Matchday\nPOSTHOG_EXIT_SURVEY_ID=\n");
    app()->useEnvironmentPath($this->envDir);
});

afterEach(function (): void {
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
});

/**
 * @param  array<string, list<array<string, mixed>>>  $existing  results per list endpoint
 */
function fakePostHogApi(array $existing = []): void
{
    Http::fake(function (Request $request) use ($existing) {
        $path = str($request->url())->after('/api/')->before('?')->toString();

        return match (true) {
            $path === 'projects/@current/' => Http::response(['id' => 42]),
            $request->method() === 'GET' => Http::response(['results' => $existing[str($path)->after('projects/42/')->toString()] ?? []]),
            $path === 'projects/42/experiments/' => Http::response(['id' => 7, 'start_date' => null]),
            $path === 'projects/42/surveys/' => Http::response(['id' => 'survey-uuid']),
            default => Http::response(['id' => 1]),
        };
    });
}

/**
 * @return list<array<string, mixed>>
 */
function sentTo(string $method, string $path): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === $method && str_starts_with($pair[0]->url(), API.$path))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

it('creates both flags, a launched experiment and the exit survey on an empty project', function (): void {
    fakePostHogApi();

    $this->artisan('matchday:posthog-setup')->assertSuccessful();

    $flags = collect(sentTo('POST', 'feature_flags/'))->keyBy('key');
    expect($flags->keys()->all())->toBe(['new-seat-picker', 'checkout-cta'])
        ->and($flags['new-seat-picker']['filters']['groups'][0]['rollout_percentage'])->toBe(20)
        ->and($flags['checkout-cta']['filters']['multivariate']['variants'])->toBe([
            ['key' => 'control', 'rollout_percentage' => 50],
            ['key' => 'test', 'rollout_percentage' => 50],
        ]);

    [$experiment] = sentTo('POST', 'experiments/');
    expect($experiment['feature_flag_key'])->toBe('checkout-cta')
        ->and($experiment)->not->toHaveKey('parameters')
        ->and(array_column($experiment['metrics'][0]['series'], 'event'))->toBe(['checkout_started', 'ticket_purchased'])
        ->and(sentTo('PATCH', 'experiments/7/'))->toBe([['start_date' => '2026-09-12T00:00:00Z']]);

    [$survey] = sentTo('POST', 'surveys/');
    expect($survey['type'])->toBe('api')
        ->and($survey['start_date'])->toBe('2026-09-12T00:00:00Z')
        ->and($survey['questions'][0]['choices'])->toBe(array_map(fn (ExitReason $reason) => $reason->label(), ExitReason::cases()));

    expect(file_get_contents($this->envDir.'/.env'))->toContain("POSTHOG_EXIT_SURVEY_ID=survey-uuid\n")
        ->and(config('posthog.surveys.checkout_exit'))->toBe('survey-uuid');
});

it('leaves everything that already exists alone, so it is safe to run again', function (): void {
    fakePostHogApi([
        'feature_flags/' => [['key' => 'new-seat-picker'], ['key' => 'checkout-cta']],
        'experiments/' => [['id' => 7, 'feature_flag_key' => 'checkout-cta', 'start_date' => '2026-09-12T00:00:00Z']],
        'surveys/' => [['id' => 'survey-uuid', 'name' => SetUpPostHog::EXIT_SURVEY_NAME, 'start_date' => '2026-09-01T00:00:00Z']],
    ]);

    $this->artisan('matchday:posthog-setup')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    expect(file_get_contents($this->envDir.'/.env'))->toContain('POSTHOG_EXIT_SURVEY_ID=survey-uuid');
});

it('launches an experiment left in draft instead of creating another', function (): void {
    fakePostHogApi([
        'experiments/' => [['id' => 9, 'feature_flag_key' => 'checkout-cta', 'start_date' => null]],
    ]);

    $this->artisan('matchday:posthog-setup', ['--days' => 7])->assertSuccessful();

    expect(sentTo('POST', 'experiments/'))->toBe([])
        ->and(sentTo('PATCH', 'experiments/9/'))->toBe([['start_date' => '2026-10-05T00:00:00Z']]);
});

it('moves an existing survey start back so backdated answers show on its results', function (): void {
    fakePostHogApi([
        'surveys/' => [['id' => 'survey-uuid', 'name' => SetUpPostHog::EXIT_SURVEY_NAME, 'start_date' => '2026-10-12T14:00:00Z']],
    ]);

    $this->artisan('matchday:posthog-setup')->assertSuccessful();

    expect(sentTo('POST', 'surveys/'))->toBe([])
        ->and(sentTo('PATCH', 'surveys/survey-uuid/'))->toBe([['start_date' => '2026-09-12T00:00:00Z']]);
});

it('refuses to run without a personal api key', function (string $key): void {
    Http::fake();
    config(['posthog.secret_key' => $key]);

    $this->artisan('matchday:posthog-setup')->assertFailed();

    Http::assertNothingSent();
})->with(['empty' => '', 'project secret key' => 'phs_secret']);
