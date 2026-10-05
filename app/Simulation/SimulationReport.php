<?php

declare(strict_types=1);

namespace App\Simulation;

use App\Analytics\Events;

/**
 * Tally of what a simulation run produced, so the funnel can be checked
 * without opening PostHog.
 */
final class SimulationReport
{
    /** @var array<string, int> persona value => supporters */
    private array $personas = [];

    /** @var array<string, int> event name => count */
    private array $events = [];

    private int $logins = 0;

    private int $purchasers = 0;

    /** @var array{true: array{supporters: int, selected: int}, false: array{supporters: int, selected: int}} */
    private array $seatPicker = [
        'true' => ['supporters' => 0, 'selected' => 0],
        'false' => ['supporters' => 0, 'selected' => 0],
    ];

    private ?bool $currentSeatPicker = null;

    /** @var array<string, array{checkouts: int, purchases: int}> variant => counts */
    private array $checkoutCta = [];

    private ?string $currentCheckoutCta = null;

    public function __construct(public readonly bool $stitching = true)
    {
        $this->personas = array_fill_keys(array_column(Persona::cases(), 'value'), 0);
        $this->events = array_fill_keys(Events::server(), 0);
    }

    public function supporter(Persona $persona): void
    {
        $this->personas[$persona->value]++;
    }

    public function event(string $name): void
    {
        $this->events[$name]++;
    }

    /**
     * Records the new-seat-picker variant for the supporter being simulated.
     */
    public function seatPicker(bool $newSeatPicker): void
    {
        $this->currentSeatPicker = $newSeatPicker;
        $this->seatPicker[$newSeatPicker ? 'true' : 'false']['supporters']++;
    }

    public function seatsSelected(): void
    {
        if ($this->currentSeatPicker !== null) {
            $this->seatPicker[$this->currentSeatPicker ? 'true' : 'false']['selected']++;
        }
    }

    /**
     * Supporters and seat-selection rate per new-seat-picker variant.
     *
     * @return array<string, array{supporters: int, selected: int, rate: float}>
     */
    public function seatPickerVariants(): array
    {
        return collect($this->seatPicker)
            ->mapWithKeys(fn (array $counts, string $variant): array => [$variant => [
                ...$counts,
                'rate' => $counts['supporters'] === 0 ? 0.0 : round($counts['selected'] / $counts['supporters'] * 100, 1),
            ]])
            ->all();
    }

    /**
     * Records the checkout-cta variant shown to the supporter at checkout.
     */
    public function checkoutCta(mixed $variant): void
    {
        $this->currentCheckoutCta = is_string($variant) ? $variant : 'none';
        $this->checkoutCta[$this->currentCheckoutCta] ??= ['checkouts' => 0, 'purchases' => 0];
        $this->checkoutCta[$this->currentCheckoutCta]['checkouts']++;
    }

    public function checkoutCtaPurchased(): void
    {
        if ($this->currentCheckoutCta !== null) {
            $this->checkoutCta[$this->currentCheckoutCta]['purchases']++;
        }
    }

    /**
     * Checkouts, purchases and conversion per checkout-cta variant.
     *
     * @return array<string, array{checkouts: int, purchases: int, rate: float}>
     */
    public function checkoutCtaVariants(): array
    {
        ksort($this->checkoutCta);

        return collect($this->checkoutCta)
            ->map(fn (array $counts): array => [
                ...$counts,
                'rate' => $counts['checkouts'] === 0 ? 0.0 : round($counts['purchases'] / $counts['checkouts'] * 100, 1),
            ])
            ->all();
    }

    public function login(): void
    {
        $this->logins++;
    }

    public function purchaser(): void
    {
        $this->purchasers++;
    }

    public function logins(): int
    {
        return $this->logins;
    }

    public function supporters(): int
    {
        return array_sum($this->personas);
    }

    /**
     * Share of simulated people who viewed a fixture and went on to buy, as
     * a PostHog person-level funnel will report it. Every supporter views a
     * fixture as their anonymous ID; without stitching, purchases land on
     * user IDs that never viewed anything, so the funnel shows none.
     */
    public function viewToPurchaseRate(): float
    {
        if (! $this->stitching || $this->supporters() === 0) {
            return 0.0;
        }

        return round($this->purchasers / $this->supporters() * 100, 1);
    }

    /**
     * @return array<string, int>
     */
    public function personas(): array
    {
        return $this->personas;
    }

    /**
     * @return array<string, int>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function count(string $event): int
    {
        return $this->events[$event];
    }

    public function total(): int
    {
        return array_sum($this->events);
    }
}
