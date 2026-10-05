<?php

declare(strict_types=1);

use App\Analytics\Events;
use App\Analytics\PersonProperties;
use App\Models\Fixture;
use App\Models\SupportersClub;
use App\Models\User;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
    AnalyticsServiceProvider::exemptPostHogCookie();
    $this->analytics = fakeAnalytics();
});

/**
 * @return array<string, mixed>
 */
function posthogSettings(TestResponse $response): array
{
    preg_match('#<script type="application/json" id="posthog-config">(.+?)</script>#', $response->getContent(), $match);

    return json_decode($match[1], true);
}

function identifiedCookie(string $distinctId): string
{
    return json_encode(['distinct_id' => $distinctId, '$user_state' => 'identified']);
}

it('tells posthog-js who is logged in, with the same person properties as the server', function (): void {
    $user = User::factory()->inSupportersClub(SupportersClub::factory()->create(['slug' => 'harbour-city-exiles']))->create();

    $settings = posthogSettings($this->actingAs($user)->get(route('fixtures.index')));

    expect($settings['identity'])->toBe([
        'distinctId' => (string) $user->id,
        'properties' => PersonProperties::for($user),
    ])->and($settings['bootstrap'])->toBe([
        'distinctID' => (string) $user->id,
        'isIdentifiedID' => true,
    ]);
});

it('sends no identity for guests, so an identified browser resets', function (): void {
    $settings = posthogSettings($this->get(route('fixtures.index')));

    expect($settings['identity'])->toBeNull()
        ->and($settings['bootstrap']['isIdentifiedID'])->toBeFalse()
        ->and($settings['bootstrap']['distinctID'])->toBeUuid();
});

it('never puts the supporter name or email in the page settings', function (): void {
    $user = User::factory()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com']);

    $script = json_encode(posthogSettings($this->actingAs($user)->get(route('fixtures.index'))));

    expect($script)->not->toContain('Priya')->not->toContain('priya@example.com');
});

it('does not credit a logged-out visitor to the user still identified in the cookie', function (): void {
    $previous = User::factory()->create();

    $this->withUnencryptedCookie('ph_phc_test_posthog', identifiedCookie((string) $previous->id))
        ->get(route('fixtures.show', Fixture::factory()->create()));

    $distinctId = $this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'];
    expect($distinctId)->not->toBe((string) $previous->id)->and($distinctId)->toBeUuid();
});

it('never aliases a new login into the previous user on a shared browser', function (): void {
    $previous = User::factory()->create();
    $next = User::factory()->create();

    $this->withUnencryptedCookie('ph_phc_test_posthog', identifiedCookie((string) $previous->id))
        ->post(route('login'), ['email' => $next->email, 'password' => 'password']);

    expect(fn () => $this->analytics->assertAliased((string) $next->id, (string) $previous->id))
        ->toThrow(ExpectationFailedException::class);
    $this->analytics->assertIdentified((string) $next->id);
});

it('still adopts an anonymous cookie id', function (): void {
    $this->withUnencryptedCookie('ph_phc_test_posthog', json_encode(['distinct_id' => 'browser-anon', '$user_state' => 'anonymous']))
        ->get(route('fixtures.show', Fixture::factory()->create()));

    expect($this->analytics->captured(Events::FIXTURE_VIEWED)->sole()['distinct_id'])->toBe('browser-anon');
});
