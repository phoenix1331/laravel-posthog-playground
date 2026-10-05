<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\Consent;
use App\Analytics\ConsentAwareAnalytics;
use App\Analytics\Events;
use App\Analytics\FakeAnalytics;
use App\Analytics\PostHogAnalytics;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

/**
 * The fake behind the real consent decorator, as the app binds it.
 */
function consentAwareFake(): FakeAnalytics
{
    $fake = new FakeAnalytics;
    app()->instance(Analytics::class, new ConsentAwareAnalytics($fake));

    return $fake;
}

function sending(): void
{
    config(['posthog.disabled' => false, 'posthog.project_token' => 'phc_test']);
}

describe('server-side capture', function (): void {
    it('captures nothing for a visitor who declined', function (): void {
        $analytics = consentAwareFake();

        $this->withUnencryptedCookie(Consent::COOKIE, Consent::DENIED)
            ->get(route('fixtures.show', Fixture::factory()->create()));

        $analytics->assertNothingCaptured();
    });

    it('captures for visitors who accepted or have not chosen yet', function (?string $choice): void {
        $analytics = consentAwareFake();
        $request = $choice === null ? $this : $this->withUnencryptedCookie(Consent::COOKIE, $choice);

        $request->get(route('fixtures.show', Fixture::factory()->create()));

        $analytics->assertCaptured(Events::FIXTURE_VIEWED);
    })->with(['granted' => [Consent::GRANTED], 'no choice' => [null]]);

    it('does not stitch identity for a supporter who declined', function (): void {
        $analytics = consentAwareFake();
        $user = User::factory()->create();

        $this->withUnencryptedCookie(Consent::COOKIE, Consent::DENIED)
            ->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        expect(fn () => $analytics->assertIdentified((string) $user->id))
            ->toThrow(ExpectationFailedException::class);
    });

    it('ignores unexpected cookie values', function (): void {
        $this->withUnencryptedCookie(Consent::COOKIE, 'whatever')->get('/up');

        expect(Consent::state())->toBe(Consent::UNKNOWN);
    });

    it('wraps every analytics driver the container binds', function (): void {
        sending();
        app()->forgetInstance(Analytics::class);

        expect(app(Analytics::class))->toBeInstanceOf(ConsentAwareAnalytics::class)
            ->and(app(Analytics::class)->inner())->toBeInstanceOf(PostHogAnalytics::class);
    });
});

describe('consent banner', function (): void {
    it('asks visitors who have not chosen', function (): void {
        sending();

        $html = $this->get(route('fixtures.index'))->assertSee(['Help us improve Matchday?', 'Accept', 'Decline'])->getContent();

        expect($html)->toMatch('/<div id="consent-banner"[^>]*role="dialog"(?![^>]*hidden)/');
    });

    it('stays hidden once a choice is made, with a link to reopen it', function (string $choice): void {
        sending();

        $html = $this->withUnencryptedCookie(Consent::COOKIE, $choice)->get(route('fixtures.index'))->getContent();

        expect($html)->toMatch('/<div id="consent-banner"[^>]*\shidden/')
            ->and($html)->toContain('data-consent-preferences');
    })->with([Consent::GRANTED, Consent::DENIED]);

    it('is not shown when the app is not sending to PostHog', function (): void {
        $this->get(route('fixtures.index'))
            ->assertDontSee('consent-banner', false)
            ->assertDontSee('Analytics preferences');
    });
});

describe('session replay masking', function (): void {
    it('keeps card details out of replays entirely', function (): void {
        $allocation = TicketAllocation::factory()->create();
        $this->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 1]);

        $html = $this->actingAs(User::factory()->create())->get(route('checkout.show'))->getContent();

        expect($html)->toMatch('/<input id="name_on_card"[^>]*class="[^"]*ph-no-capture/')
            ->and($html)->toMatch('/<input id="card_number"[^>]*class="[^"]*ph-no-capture/');
    });

    it('masks the supporter name and booking reference', function (): void {
        $booking = Booking::factory()->create();

        $this->actingAs($booking->user)->get(route('bookings.show', $booking))
            ->assertSee('data-ph-mask>'.$booking->reference, false)
            ->assertSee('data-ph-mask>'.e($booking->user->name), false);
    });
});
