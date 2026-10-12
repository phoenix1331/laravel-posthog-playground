<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The PostHog management API for the configured project: flags,
 * experiments and surveys. Used by matchday:posthog-setup, never on the
 * request path.
 *
 * Needs a personal API key (phx_...) with write access to feature flags,
 * experiments and surveys. A project secret key (phs_...) only serves flag
 * definitions and is refused here.
 */
class PostHogProject
{
    private ?int $projectId = null;

    public static function configured(): bool
    {
        return str_starts_with((string) config('posthog.secret_key'), 'phx_');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flag(string $key): ?array
    {
        return $this->firstWhere('feature_flags/', ['search' => $key], fn (array $flag): bool => $flag['key'] === $key);
    }

    /**
     * @param  array<string, mixed>  $flag
     * @return array<string, mixed>
     */
    public function createFlag(array $flag): array
    {
        return $this->send('post', 'feature_flags/', $flag);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function experimentFor(string $flagKey): ?array
    {
        return $this->firstWhere('experiments/', [], fn (array $experiment): bool => $experiment['feature_flag_key'] === $flagKey);
    }

    /**
     * @param  array<string, mixed>  $experiment
     * @return array<string, mixed>
     */
    public function createExperiment(array $experiment): array
    {
        return $this->send('post', 'experiments/', $experiment);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateExperiment(int $id, array $changes): array
    {
        return $this->send('patch', "experiments/{$id}/", $changes);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function surveyNamed(string $name): ?array
    {
        return $this->firstWhere('surveys/', ['search' => $name], fn (array $survey): bool => $survey['name'] === $name && ! ($survey['archived'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $survey
     * @return array<string, mixed>
     */
    public function createSurvey(array $survey): array
    {
        return $this->send('post', 'surveys/', $survey);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateSurvey(string $id, array $changes): array
    {
        return $this->send('patch', "surveys/{$id}/", $changes);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(array<string, mixed>): bool  $matches
     * @return array<string, mixed>|null
     */
    private function firstWhere(string $path, array $query, callable $matches): ?array
    {
        $results = $this->send('get', $path, [...$query, 'limit' => 200])['results'] ?? [];

        return collect($results)->first($matches);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = []): array
    {
        $response = $this->http()->{$method}("projects/{$this->projectId()}/{$path}", $data);

        if ($response->failed()) {
            throw new RuntimeException("PostHog API {$method} {$path} failed ({$response->status()}): ".$response->body());
        }

        return $response->json() ?? [];
    }

    private function projectId(): int
    {
        if ($this->projectId !== null) {
            return $this->projectId;
        }

        $response = $this->http()->get('projects/@current/');

        if ($response->failed()) {
            throw new RuntimeException("PostHog API rejected the personal API key ({$response->status()}): ".$response->body());
        }

        return $this->projectId = (int) $response->json('id');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('posthog.ui_host'), '/').'/api/')
            ->withToken((string) config('posthog.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
