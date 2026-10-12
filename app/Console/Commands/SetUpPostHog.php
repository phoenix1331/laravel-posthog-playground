<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Events;
use App\Analytics\PostHogProject;
use App\Enums\ExitReason;
use App\Features\CheckoutCta;
use App\Features\NewSeatPicker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('matchday:posthog-setup
    {--days=30 : Backdate the experiment start this many days, to match the simulator}')]
#[Description('Create the flags, experiment and exit survey this app expects in PostHog')]
class SetUpPostHog extends Command
{
    public const string EXIT_SURVEY_NAME = 'Checkout exit survey';

    public const string EXPERIMENT_NAME = 'Checkout CTA wording';

    public function handle(PostHogProject $posthog): int
    {
        if (! PostHogProject::configured()) {
            $this->components->error('Set POSTHOG_PERSONAL_API_KEY to a personal API key (phx_...) with write access to feature flags, experiments and surveys.');

            return self::FAILURE;
        }

        $this->ensureFlag($posthog, new NewSeatPicker()->name, [
            'groups' => [['properties' => [], 'rollout_percentage' => NewSeatPicker::ROLLOUT_PERCENTAGE]],
        ]);

        $this->ensureFlag($posthog, CheckoutCta::key(), [
            'groups' => [['properties' => [], 'rollout_percentage' => 100]],
            'multivariate' => ['variants' => collect(CheckoutCta::VARIANTS)
                ->map(fn (int $percentage, string $variant): array => ['key' => $variant, 'rollout_percentage' => $percentage])
                ->values()
                ->all()],
        ]);

        $this->ensureExperiment($posthog);

        $surveyId = $this->ensureExitSurvey($posthog);
        $this->storeSurveyId($surveyId);

        $this->newLine();
        $this->components->info('PostHog is set up. Run make queue-restart so the worker picks up the survey ID.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function ensureFlag(PostHogProject $posthog, string $key, array $filters): void
    {
        if ($posthog->flag($key) !== null) {
            $this->components->twoColumnDetail("Flag <fg=cyan>{$key}</>", 'exists, left as is');

            return;
        }

        $posthog->createFlag(['key' => $key, 'name' => Str::headline($key), 'active' => true, 'filters' => $filters]);

        $this->components->twoColumnDetail("Flag <fg=cyan>{$key}</>", '<fg=green>created</>');
    }

    /**
     * Linked to the existing checkout-cta flag and launched with a backdated
     * start, so simulated traffic counts towards the results.
     */
    private function ensureExperiment(PostHogProject $posthog): void
    {
        $label = 'Experiment <fg=cyan>'.CheckoutCta::key().'</>';
        $experiment = $posthog->experimentFor(CheckoutCta::key());

        if ($experiment !== null && $experiment['start_date'] !== null) {
            $this->components->twoColumnDetail($label, 'exists and running, left as is');

            return;
        }

        $experiment ??= $posthog->createExperiment([
            'name' => self::EXPERIMENT_NAME,
            'description' => 'Does "Secure my seats for £X" turn more checkout starts into purchases than "Pay £X"?',
            'feature_flag_key' => CheckoutCta::key(),
            'type' => 'product',
            'metrics' => [[
                'kind' => 'ExperimentMetric',
                'uuid' => (string) Str::uuid(),
                'name' => 'Checkout to purchase',
                'metric_type' => 'funnel',
                'series' => [
                    ['kind' => 'EventsNode', 'event' => Events::CHECKOUT_STARTED, 'name' => Events::CHECKOUT_STARTED],
                    ['kind' => 'EventsNode', 'event' => Events::TICKET_PURCHASED, 'name' => Events::TICKET_PURCHASED],
                ],
            ]],
        ]);

        $startsAt = now()->subDays((int) $this->option('days'))->startOfDay();
        $posthog->updateExperiment($experiment['id'], ['start_date' => $startsAt->toIso8601ZuluString()]);

        $this->components->twoColumnDetail($label, "<fg=green>launched from {$startsAt->toDateString()}</>");
    }

    /**
     * An "API" survey: the app shows its own dialog, and answers reach
     * PostHog as "survey sent" events carrying the reason's label.
     */
    private function ensureExitSurvey(PostHogProject $posthog): string
    {
        $label = 'Survey <fg=cyan>'.self::EXIT_SURVEY_NAME.'</>';
        $survey = $posthog->surveyNamed(self::EXIT_SURVEY_NAME);

        if ($survey !== null) {
            $this->components->twoColumnDetail($label, 'exists, left as is');

            return (string) $survey['id'];
        }

        $survey = $posthog->createSurvey([
            'name' => self::EXIT_SURVEY_NAME,
            'description' => 'Asked on exit intent from the checkout page',
            'type' => 'api',
            'questions' => [[
                'type' => 'single_choice',
                'question' => 'Before you go, what stopped you?',
                'choices' => array_map(fn (ExitReason $reason): string => $reason->label(), ExitReason::cases()),
            ]],
            'start_date' => now()->toIso8601ZuluString(),
        ]);

        $this->components->twoColumnDetail($label, '<fg=green>created and launched</>');

        return (string) $survey['id'];
    }

    private function storeSurveyId(string $surveyId): void
    {
        if (config('posthog.surveys.checkout_exit') === $surveyId) {
            return;
        }

        $path = $this->laravel->environmentFilePath();
        $env = file_get_contents($path);
        $line = "POSTHOG_EXIT_SURVEY_ID={$surveyId}";

        $updated = preg_match('/^POSTHOG_EXIT_SURVEY_ID=.*$/m', $env) === 1
            ? preg_replace('/^POSTHOG_EXIT_SURVEY_ID=.*$/m', $line, $env)
            : rtrim($env).PHP_EOL.$line.PHP_EOL;

        file_put_contents($path, $updated);
        config(['posthog.surveys.checkout_exit' => $surveyId]);

        $this->components->twoColumnDetail('<fg=cyan>.env</>', "<fg=green>POSTHOG_EXIT_SURVEY_ID={$surveyId}</>");
    }
}
