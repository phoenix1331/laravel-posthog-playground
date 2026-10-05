<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\Basket;
use App\Models\Booking;
use App\Models\Fixture;
use App\Models\SupportersClub;
use App\Models\TicketAllocation;
use App\Models\TravelOption;
use Carbon\CarbonInterface;

/**
 * Builds the property bag for an analytics event from domain models.
 *
 * Only the keys listed in ALLOWED can be emitted, which is how the "no
 * personal data beyond the user ID" rule is enforced: names, emails and
 * card details have no way in. Add a key here before using it. The one
 * open family is "$feature/<flag key>", PostHog's experiment variant tag.
 */
final class EventProperties
{
    public const array ALLOWED = [
        // Fixture
        'fixture_id',
        'fixture',
        'competition',
        'home_club',
        'away_club',
        'kicks_off_at',
        'days_until_kick_off',
        // Seats
        'stand',
        'price_band',
        'supporter_side',
        'unit_price_pence',
        'quantity',
        'tickets_remaining',
        'near_sell_out',
        // Travel
        'has_travel',
        'travel_price_pence',
        // Money
        'ticket_total_pence',
        'travel_total_pence',
        'total_pence',
        'revenue',
        'currency',
        // Booking
        'booking_reference',
        // Group
        'supporters_club',
        // Feature flags (PostHog's reserved names)
        '$feature_flag',
        '$feature_flag_response',
    ];

    private const string EXPERIMENT_PREFIX = '$feature/';

    /**
     * @var array<string, scalar|null>
     */
    private array $properties = [];

    private function __construct(private readonly CarbonInterface $at) {}

    /**
     * @param  CarbonInterface|null  $at  when the event happened; backdated
     *                                    events (the simulator) pass their own time so relative
     *                                    properties such as days_until_kick_off stay correct
     */
    public static function make(?CarbonInterface $at = null): self
    {
        return new self($at ?? now());
    }

    public function fixture(Fixture $fixture): self
    {
        return $this->with([
            'fixture_id' => $fixture->id,
            'fixture' => $fixture->slug,
            'competition' => $fixture->competition->slug,
            'home_club' => $fixture->homeClub->slug,
            'away_club' => $fixture->awayClub->slug,
            'kicks_off_at' => $fixture->kicks_off_at->toIso8601String(),
            'days_until_kick_off' => (int) max(0, $this->at->diffInDays($fixture->kicks_off_at)),
        ]);
    }

    /**
     * "home" or "away" is the supporter's side, taken from the stand: the
     * away end is the only place away supporters sit.
     */
    public function seats(TicketAllocation $allocation, int $quantity): self
    {
        return $this->with([
            'stand' => $allocation->stand->name,
            'price_band' => $allocation->stand->price_band->value,
            'supporter_side' => $allocation->stand->is_away_end ? 'away' : 'home',
            'unit_price_pence' => $allocation->stand->price_pence,
            'quantity' => $quantity,
            'tickets_remaining' => $allocation->remaining(),
            'near_sell_out' => $allocation->isNearSellOut(),
        ]);
    }

    public function travel(?TravelOption $travelOption): self
    {
        return $this->with([
            'has_travel' => $travelOption !== null,
            'travel_price_pence' => $travelOption?->price_pence,
        ]);
    }

    public function basket(Basket $basket): self
    {
        return $this->fixture($basket->fixture)
            ->seats($basket->ticketAllocation, $basket->quantity)
            ->travel($basket->travelOption)
            ->with([
                'ticket_total_pence' => $basket->ticketTotalPence(),
                'travel_total_pence' => $basket->travelTotalPence(),
                'total_pence' => $basket->totalPence(),
            ]);
    }

    /**
     * Includes revenue in pounds with a currency so PostHog revenue
     * analytics can pick it up.
     */
    public function booking(Booking $booking): self
    {
        return $this->fixture($booking->fixture)
            ->seats($booking->ticketAllocation, $booking->quantity)
            ->travel($booking->travelOption)
            ->with([
                'booking_reference' => $booking->reference,
                'ticket_total_pence' => $booking->ticket_total_pence,
                'travel_total_pence' => $booking->travel_total_pence,
                'total_pence' => $booking->total_pence,
                'revenue' => round($booking->total_pence / 100, 2),
                'currency' => 'GBP',
            ]);
    }

    /**
     * Exposure to a flag, in the shape PostHog experiments read.
     */
    public function featureFlag(string $flag, bool|string $response): self
    {
        return $this->with(['$feature_flag' => $flag, '$feature_flag_response' => $response]);
    }

    /**
     * The variant of an experiment this person is in, so PostHog can
     * attribute the event to it.
     */
    public function experiment(string $flag, bool|string $variant): self
    {
        return $this->with([self::EXPERIMENT_PREFIX.$flag => $variant]);
    }

    public function supportersClub(?SupportersClub $supportersClub): self
    {
        return $this->with(['supporters_club' => $supportersClub?->slug]);
    }

    /**
     * @param  array<string, scalar|null>  $properties
     */
    public function with(array $properties): self
    {
        $unknown = array_filter(
            array_diff(array_keys($properties), self::ALLOWED),
            fn (string $key): bool => ! str_starts_with($key, self::EXPERIMENT_PREFIX),
        );

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Analytics property not in the allow-list: '.implode(', ', $unknown));
        }

        $this->properties = [...$this->properties, ...$properties];

        return $this;
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return array_filter($this->properties, fn (mixed $value): bool => $value !== null);
    }
}
