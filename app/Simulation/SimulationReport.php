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

    public function __construct()
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
