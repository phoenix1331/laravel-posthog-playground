<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Events;
use App\Providers\AnalyticsServiceProvider;
use App\Simulation\Persona;
use App\Simulation\SimulationReport;
use App\Simulation\Simulator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('matchday:simulate
    {--users=500 : Simulated supporters}
    {--days=30 : Spread journeys over this many past days}
    {--seed= : Seed for a repeatable run}
    {--no-stitching : Skip login at checkout, so anonymous browsing is never linked to the buyer}')]
#[Description('Generate backdated supporter journeys so PostHog dashboards have data')]
class SimulateMatchday extends Command
{
    public function handle(Simulator $simulator): int
    {
        $users = (int) $this->option('users');
        $days = (int) $this->option('days');
        $seed = $this->option('seed');

        if ($users < 1 || $days < 1) {
            $this->components->error('--users and --days must both be at least 1.');

            return self::INVALID;
        }

        $this->describeDestination();

        $bar = $this->output->createProgressBar($users);

        try {
            $report = $simulator->run(
                supporters: $users,
                days: $days,
                seed: $seed === null ? null : (int) $seed,
                stitching: ! $this->option('no-stitching'),
                tick: fn () => $bar->advance(),
            );
        } catch (RuntimeException $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Persona', 'Supporters'], collect($report->personas())
            ->map(fn (int $count, string $persona): array => [Persona::from($persona)->name, $count])
            ->values());

        $viewed = max(1, $report->count(Events::FIXTURE_VIEWED));
        $this->table(['Event', 'Count', '% of fixture views'], collect($report->events())
            ->map(fn (int $count, string $event): array => [$event, $count, round($count / $viewed * 100, 1).'%'])
            ->values());

        $this->table(['new-seat-picker', 'Supporters', 'Selected seats'], collect($report->seatPickerVariants())
            ->map(fn (array $variant, string $value): array => [
                $value === 'true' ? 'stadium map' : 'list',
                $variant['supporters'],
                $variant['rate'].'%',
            ])
            ->values());

        $this->components->info("{$report->total()} events generated.");
        $this->describeStitching($report);

        return self::SUCCESS;
    }

    private function describeStitching(SimulationReport $report): void
    {
        $rate = $report->viewToPurchaseRate().'%';

        if ($report->stitching) {
            $this->components->twoColumnDetail('Identity stitching', "on ({$report->logins()} logins aliased)");
            $this->components->twoColumnDetail('View to purchase, per person in PostHog', $rate);

            return;
        }

        $this->components->twoColumnDetail('Identity stitching', '<fg=yellow>off</>');
        $this->components->twoColumnDetail('View to purchase, per person in PostHog', $rate);
        $this->components->warn('Purchases land on user IDs that never viewed a fixture. Compare with a run without --no-stitching.');
    }

    private function describeDestination(): void
    {
        if (AnalyticsServiceProvider::sending()) {
            $this->components->info('Sending to PostHog via the "'.config('posthog.queue').'" queue. Make sure the queue worker is running.');

            return;
        }

        $channel = config('posthog.log_channel');
        $this->components->warn(filled($channel)
            ? "No PostHog token: events go to the null driver and the \"{$channel}\" log channel."
            : 'No PostHog token: events go to the null driver and are dropped. Set POSTHOG_LOG_CHANNEL to see them.');
    }
}
