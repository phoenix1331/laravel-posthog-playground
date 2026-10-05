<?php

declare(strict_types=1);

namespace App\Simulation;

use App\Analytics\DistinctId;
use App\Analytics\Events;
use App\Analytics\Flags\FlagScope;
use App\Events\CheckoutAbandoned;
use App\Events\CheckoutStarted;
use App\Events\FixtureViewed;
use App\Events\SeatSelected;
use App\Events\TicketPurchased;
use App\Events\TravelAdded;
use App\Features\CheckoutCta;
use App\Features\NewSeatPicker;
use App\Models\Basket;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Generates realistic, backdated traffic so PostHog dashboards have data on
 * first run.
 *
 * It fires the same domain events as the real app, so analytics flows
 * through the same subscriber, contract and queue. Baskets and bookings are
 * built in memory and never saved: stock, users and bookings are untouched.
 *
 * Each simulated supporter browses as "sim-anon-<hex>" and logs in at
 * checkout as "sim-user-<hex>", firing Laravel's real Login event so the
 * app's own identity stitching aliases the two. With stitching off the login
 * is skipped: the IDs stay separate people and the view-to-purchase funnel
 * collapses, which is the point of the comparison. The "sim-" prefix makes
 * simulated people easy to filter out in PostHog.
 *
 * Feature flags are assigned through Pennant exactly as the app does, so a
 * simulated visitor gets the same variant (and exposure event, backdated)
 * PostHog or the fallback would give them; the stadium-map picker nudges
 * seat selection up slightly and the "test" checkout button lifts purchases,
 * so both the rollout and the experiment show an effect.
 */
class Simulator
{
    /** Latest a journey may start, leaving room for abandonment to land in the past */
    private const int JOURNEY_HEADROOM_SECONDS = 3 * 3600;

    /** Share of non-fan supporters who belong to a supporters' club */
    private const int CLUB_MEMBERSHIP_CHANCE = 15;

    /** Extra percentage points of seat selection for supporters shown the stadium map */
    private const int NEW_SEAT_PICKER_LIFT = 5;

    /** Extra percentage points of purchase for supporters shown the "test" checkout button */
    private const int CHECKOUT_CTA_LIFT = 8;

    private Randomizer $random;

    private SimulationReport $report;

    private bool $stitching = true;

    /** @var Collection<int, Fixture> */
    private Collection $fixtures;

    /** @var Collection<int, SupportersClub> */
    private Collection $supportersClubs;

    public function __construct(private readonly DistinctId $distinctId) {}

    /**
     * @param  bool  $stitching  log supporters in at checkout so their anonymous browsing is aliased to them
     * @param  (callable(): void)|null  $tick  called once per simulated supporter
     */
    public function run(int $supporters, int $days, ?int $seed = null, bool $stitching = true, ?callable $tick = null): SimulationReport
    {
        $this->random = new Randomizer($seed === null ? new Mt19937 : new Mt19937($seed));
        $this->stitching = $stitching;
        $this->report = new SimulationReport($stitching);
        $this->loadCatalogue();

        $windowEnd = CarbonImmutable::now()->subSeconds(self::JOURNEY_HEADROOM_SECONDS);
        $windowSeconds = max(1, $days * 86_400 - self::JOURNEY_HEADROOM_SECONDS);

        for ($i = 0; $i < $supporters; $i++) {
            $startsAt = $windowEnd->subSeconds($this->random->getInt(0, $windowSeconds));

            $this->journey($this->pickPersona(), $startsAt);

            if ($tick !== null) {
                $tick();
            }
        }

        return $this->report;
    }

    private function journey(Persona $persona, CarbonImmutable $at): void
    {
        $this->report->supporter($persona);

        $id = bin2hex($this->random->getBytes(8));
        $this->distinctId->useAnonymousId("sim-anon-{$id}");
        $supporter = $this->supporter("sim-user-{$id}", $persona);

        // Same Pennant call the fixture page makes, so the variant (and its
        // exposure event) matches what this visitor would get in the app
        $newSeatPicker = Feature::for(new FlagScope("sim-anon-{$id}", at: $at))->active(NewSeatPicker::class);
        $this->report->seatPicker($newSeatPicker);

        $viewed = $this->browse($persona, $supporter, $at);

        $lift = $newSeatPicker ? self::NEW_SEAT_PICKER_LIFT : 0;

        if (! $this->chance($persona->selectsSeats() + $lift)) {
            return;
        }

        $basket = $this->selectSeats($persona, $viewed, $at);

        if ($basket === null) {
            return;
        }

        if ($this->chance($persona->addsTravel())) {
            $this->addTravel($basket, $at);
        }

        if (! $this->chance($persona->startsCheckout())) {
            return;
        }

        // Checkout needs an account, so this is where the supporter logs in
        if ($this->stitching) {
            event(new Login('web', $supporter, false));
            $this->report->login();
        }

        $at = $this->later($at, 60, 300);

        // The variant CheckoutController would render for this supporter
        $cta = Feature::for(FlagScope::for($supporter, $at))->value(CheckoutCta::class);
        $this->report->checkoutCta($cta);

        CheckoutStarted::dispatch($basket, $supporter, $at);
        $this->report->event(Events::CHECKOUT_STARTED);

        if (! $this->chance($this->purchaseChance($persona, $cta))) {
            CheckoutAbandoned::dispatch($basket, $supporter, $at->addMinutes(Basket::ABANDON_AFTER_MINUTES));
            $this->report->event(Events::CHECKOUT_ABANDONED);

            return;
        }

        TicketPurchased::dispatch($this->booking($basket, $supporter, $this->later($at, 60, 240)));
        $this->report->event(Events::TICKET_PURCHASED);
        $this->report->purchaser();
        $this->report->checkoutCtaPurchased();
    }

    /**
     * @return Collection<int, Fixture> fixtures viewed, in order
     */
    private function browse(Persona $persona, User $supporter, CarbonImmutable &$at): Collection
    {
        [$min, $max] = $persona->fixturesViewed();
        $pool = $this->fixturesFor($persona, $supporter);
        $count = min($pool->count(), $this->random->getInt($min, $max));

        $viewed = collect($this->random->pickArrayKeys($pool->all(), $count))
            ->map(fn (int $key): Fixture => $pool[$key]);

        foreach ($this->random->shuffleArray($viewed->all()) as $fixture) {
            $at = $this->later($at, 20, 240);
            FixtureViewed::dispatch($fixture, null, $at);
            $this->report->event(Events::FIXTURE_VIEWED);
        }

        return $viewed->values();
    }

    /**
     * @param  Collection<int, Fixture>  $viewed
     */
    private function selectSeats(Persona $persona, Collection $viewed, CarbonImmutable &$at): ?Basket
    {
        [$min, $max] = $persona->tickets();
        $quantity = $this->random->getInt($min, $max);
        $allocation = $this->chooseAllocation($persona, $viewed, $quantity);

        if ($allocation === null) {
            return null;
        }

        $basket = new Basket([
            'fixture_id' => $allocation->fixture_id,
            'ticket_allocation_id' => $allocation->id,
            'quantity' => $quantity,
        ]);
        $basket->setRelation('fixture', $allocation->fixture)
            ->setRelation('ticketAllocation', $allocation)
            ->setRelation('travelOption', null);

        $at = $this->later($at, 30, 180);
        SeatSelected::dispatch($basket, null, $at);
        $this->report->event(Events::SEAT_SELECTED);
        $this->report->seatsSelected();

        return $basket;
    }

    private function addTravel(Basket $basket, CarbonImmutable &$at): void
    {
        $coaches = $basket->fixture->travelOptions
            ->filter(fn (TravelOption $coach): bool => $coach->remaining() >= $basket->quantity)
            ->values();

        if ($coaches->isEmpty()) {
            return;
        }

        $coach = $coaches[$this->random->getInt(0, $coaches->count() - 1)];
        $basket->travel_option_id = $coach->id;
        $basket->setRelation('travelOption', $coach);

        $at = $this->later($at, 20, 120);
        TravelAdded::dispatch($basket, null, $at);
        $this->report->event(Events::TRAVEL_ADDED);
    }

    /**
     * Loyal fans head for the away end, bargain hunters for the cheapest
     * seats on any fixture they looked at, everyone else picks a stand on
     * the last fixture they viewed.
     *
     * @param  Collection<int, Fixture>  $viewed
     */
    private function chooseAllocation(Persona $persona, Collection $viewed, int $quantity): ?TicketAllocation
    {
        $fixtures = $persona === Persona::BargainHunter ? $viewed : $viewed->take(-1);

        $available = $fixtures
            ->flatMap(fn (Fixture $fixture) => $fixture->ticketAllocations)
            ->filter(fn (TicketAllocation $allocation): bool => $allocation->remaining() >= $quantity)
            ->values();

        if ($available->isEmpty()) {
            return null;
        }

        if ($persona === Persona::BargainHunter) {
            return $available->sortBy(fn (TicketAllocation $allocation) => $allocation->stand->price_pence)->first();
        }

        $awayEnd = $available->first(fn (TicketAllocation $allocation): bool => $allocation->stand->is_away_end);

        if ($persona === Persona::LoyalAwayFan && $awayEnd !== null) {
            return $awayEnd;
        }

        return $available[$this->random->getInt(0, $available->count() - 1)];
    }

    /**
     * Loyal fans only look at their own club's away fixtures.
     *
     * @return Collection<int, Fixture>
     */
    private function fixturesFor(Persona $persona, User $supporter): Collection
    {
        $club = $supporter->supportersClub?->club_id;

        if ($persona !== Persona::LoyalAwayFan || $club === null) {
            return $this->fixtures;
        }

        $away = $this->fixtures->where('away_club_id', $club)->values();

        return $away->isEmpty() ? $this->fixtures : $away;
    }

    /**
     * An unsaved user standing in for the supporter once they log in. Its ID
     * is their simulated user distinct ID.
     */
    private function supporter(string $distinctId, Persona $persona): User
    {
        $joinsClub = $persona === Persona::LoyalAwayFan || $this->chance(self::CLUB_MEMBERSHIP_CHANCE);
        $supportersClub = $joinsClub && $this->supportersClubs->isNotEmpty()
            ? $this->supportersClubs[$this->random->getInt(0, $this->supportersClubs->count() - 1)]
            : null;

        // String key: User's integer key type would cast "sim-..." to 0
        $user = (new User)->setKeyType('string')->setIncrementing(false)
            ->forceFill(['id' => $distinctId, 'supporters_club_id' => $supportersClub?->id]);

        return $user->setRelation('supportersClub', $supportersClub);
    }

    private function booking(Basket $basket, User $supporter, CarbonImmutable $at): Booking
    {
        $booking = (new Booking)->forceFill([
            'reference' => 'SIM-'.strtoupper(bin2hex($this->random->getBytes(4))),
            'user_id' => $supporter->id,
            'fixture_id' => $basket->fixture_id,
            'ticket_allocation_id' => $basket->ticket_allocation_id,
            'travel_option_id' => $basket->travel_option_id,
            'quantity' => $basket->quantity,
            'ticket_total_pence' => $basket->ticketTotalPence(),
            'travel_total_pence' => $basket->travelTotalPence(),
            'total_pence' => $basket->totalPence(),
            'purchased_at' => $at,
        ]);

        return $booking->setRelation('user', $supporter)
            ->setRelation('fixture', $basket->fixture)
            ->setRelation('ticketAllocation', $basket->ticketAllocation)
            ->setRelation('travelOption', $basket->travelOption);
    }

    private function loadCatalogue(): void
    {
        $this->fixtures = Fixture::upcoming()
            ->with(['competition', 'homeClub', 'awayClub', 'ticketAllocations.stand', 'ticketAllocations.fixture', 'travelOptions'])
            ->get()
            ->filter(fn (Fixture $fixture): bool => $fixture->ticketAllocations->isNotEmpty())
            ->values();

        if ($this->fixtures->isEmpty()) {
            throw new RuntimeException('No upcoming fixtures with tickets. Run "make fresh" to seed some.');
        }

        // Reuse the loaded fixture so allocation->fixture carries its relations
        $this->fixtures->each(fn (Fixture $fixture) => $fixture->ticketAllocations
            ->each(fn (TicketAllocation $allocation) => $allocation->setRelation('fixture', $fixture)));

        $this->supportersClubs = SupportersClub::orderBy('id')->get();
    }

    private function pickPersona(): Persona
    {
        $roll = $this->random->getInt(1, array_sum(array_map(fn (Persona $persona): int => $persona->weight(), Persona::cases())));

        foreach (Persona::cases() as $persona) {
            $roll -= $persona->weight();

            if ($roll <= 0) {
                return $persona;
            }
        }

        return Persona::Browser;
    }

    /**
     * The "test" button converts slightly better, so the experiment has a
     * winner to find. Personas who never pay are unaffected.
     */
    private function purchaseChance(Persona $persona, mixed $cta): int
    {
        if ($persona->purchases() === 0 || $cta !== CheckoutCta::TEST) {
            return $persona->purchases();
        }

        return $persona->purchases() + self::CHECKOUT_CTA_LIFT;
    }

    private function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    private function later(CarbonImmutable $at, int $minSeconds, int $maxSeconds): CarbonImmutable
    {
        return $at->addSeconds($this->random->getInt($minSeconds, $maxSeconds));
    }
}
