<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\TicketAllocation;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Arr;
use Laravel\Dusk\Browser;

/*
|--------------------------------------------------------------------------
| Supporter journeys
|--------------------------------------------------------------------------
|
| Real browser sessions through the seeded app, run with `make dusk`. With
| PostHog configured each one accepts analytics, so it produces client
| events ($pageview, autocapture) and a masked session replay; without a
| token they still exercise every page in a real browser.
|
| Dusk reuses one browser across tests, so each journey starts by clearing
| cookies and storage: a genuinely new, anonymous visitor every time.
|
*/

/**
 * Forget everything about the previous journey: Laravel session, consent,
 * and posthog-js identity (cookie and localStorage).
 */
function asNewVisitor(Browser $browser): Browser
{
    $browser->visit('/');
    $browser->driver->manage()->deleteAllCookies();
    $browser->script('window.localStorage.clear(); window.sessionStorage.clear();');

    return $browser->visit('/');
}

/**
 * Lets PostHog capture and record, when the banner is shown at all.
 */
function acceptAnalytics(Browser $browser): Browser
{
    if ($browser->element('#consent-banner:not([hidden])') !== null) {
        $browser->click('@consent-accept')->pause(300);
    }

    return $browser;
}

/**
 * A fixture with stock in at least two stands, so either picker has a choice.
 */
function fixtureWithStock(): Fixture
{
    return Fixture::upcoming()
        ->whereHas('ticketAllocations', fn ($query) => $query->whereColumn('sold', '<', 'capacity'), '>=', 2)
        ->inRandomOrder()
        ->firstOrFail();
}

/**
 * Picks seats with whichever picker this visitor was given: the stadium map
 * (new-seat-picker rollout) or the original list.
 */
function selectSeats(Browser $browser, int $quantity): Browser
{
    if ($browser->element('[data-seat-picker="map"]') !== null) {
        return $browser
            ->click('[dusk="map-stand"]:not([data-sold-out])')
            ->select('#map-quantity', (string) $quantity)
            ->click('@select-seats')
            ->waitForLocation('/basket');
    }

    return $browser
        ->select('ul[data-seat-picker="list"] form select[name="quantity"]', '1')
        ->click('@select-seats')
        ->waitForLocation('/basket');
}

function logInAt(Browser $browser, string $email): Browser
{
    return $browser
        ->waitForLocation('/login')
        ->type('email', $email)
        ->type('password', UserSeeder::PASSWORD)
        ->click('@log-in');
}

function aSupporter(): string
{
    return UserSeeder::emailFor(Arr::random(array_keys(UserSeeder::SUPPORTERS)));
}

/**
 * posthog-js batches client events; give it a moment to send them before
 * the browser closes.
 */
function letAnalyticsFlush(Browser $browser): void
{
    $browser->pause(1500);
}

it('browses a few fixtures without buying', function (): void {
    $this->browse(function (Browser $browser): void {
        acceptAnalytics(asNewVisitor($browser))->assertSee('Upcoming fixtures');

        $browser->click('@fixture-card')->assertSee('Tickets')->back();
        $browser->visit('/fixtures/'.fixtureWithStock()->slug)->assertSee('Tickets');

        letAnalyticsFlush($browser);
    });
});

it('buys tickets, adds away travel and pays', function (): void {
    $fixture = fixtureWithStock();

    $this->browse(function (Browser $browser) use ($fixture): void {
        acceptAnalytics(asNewVisitor($browser))
            ->visit('/fixtures/'.$fixture->slug)
            ->assertSee($fixture->homeClub->name);

        selectSeats($browser, 2)->assertSee('Your basket');

        if ($browser->element('@add-coach') !== null) {
            $browser->click('@add-coach')->waitForLocation('/basket')->assertSee('coach from');
        }

        $browser->visit('/checkout');
        logInAt($browser, aSupporter())->waitForLocation('/checkout');

        $browser->type('name_on_card', 'Dusk Supporter')
            ->type('card_number', '4242 4242 4242 4242')
            ->click('@pay')
            ->waitForText('Booking confirmed')
            ->assertSee('You\'re going to');

        letAnalyticsFlush($browser);
    });
});

it('reaches checkout and walks away', function (): void {
    $fixture = fixtureWithStock();

    $this->browse(function (Browser $browser) use ($fixture): void {
        acceptAnalytics(asNewVisitor($browser))->visit('/fixtures/'.$fixture->slug);
        selectSeats($browser, 1);

        $browser->visit('/checkout');
        logInAt($browser, aSupporter())->waitForLocation('/checkout')->assertPresent('@pay');

        // Leave without paying: the abandonment sweep flags it 30 minutes later
        $browser->visit('/');

        letAnalyticsFlush($browser);
    });
});

it('tries a declined card before giving up', function (): void {
    $fixture = fixtureWithStock();

    $this->browse(function (Browser $browser) use ($fixture): void {
        acceptAnalytics(asNewVisitor($browser))->visit('/fixtures/'.$fixture->slug);
        selectSeats($browser, 1);

        $browser->visit('/checkout');
        logInAt($browser, aSupporter())->waitForLocation('/checkout');

        $browser->type('name_on_card', 'Dusk Supporter')
            ->type('card_number', '4000 0000 0000 0002')
            ->click('@pay')
            ->waitForText('Your card was declined')
            ->assertPathIs('/checkout')
            ->assertPresent('@pay');

        letAnalyticsFlush($browser);
    });
});

it('only offers stands that still have tickets', function (): void {
    $soldOut = TicketAllocation::whereColumn('sold', '>=', 'capacity')->with('fixture')->first();

    if ($soldOut === null) {
        $this->markTestSkipped('No sold-out stand in the seeded data.');
    }

    $this->browse(function (Browser $browser) use ($soldOut): void {
        asNewVisitor($browser)->visit('/fixtures/'.$soldOut->fixture->slug)->assertSee('Sold out');
    });
});
