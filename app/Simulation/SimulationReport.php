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
