<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use Illuminate\Contracts\Cache\Repository;
use PostHog\Client;
use Throwable;

/**
 * Evaluates the same visitors in local and remote mode and times both, for
 * the flags debug page.
 *
 * Setup is timed separately from evaluation because that is where the modes
 * differ: local pays to load definitions (from cache, or once from PostHog
 * when the cache is cold) and then evaluates in PHP; remote is free to set
 * up and pays a /flags round trip for every new visitor.
 */
class FlagEvaluationBenchmark
{
    public function __construct(
        private readonly FlagClientFactory $clients,
        private readonly Repository $cache,
    ) {}

    /**
     * @param  list<FlagScope>  $scopes
     * @return array{local: array<string, mixed>|null, remote: array<string, mixed>, agreement: array<string, bool>, definitions: array{cached: bool, flags: int}}
     */
    public function run(array $scopes): array
    {
        $definitionsWereCached = $this->cache->has(CachedFlagDefinitions::KEY);

        $local = $this->clients->localAvailable()
            ? $this->measure(PostHogFlags::LOCAL, fn (): Client => $this->clients->local(), $scopes)
            : null;
        $remote = $this->measure(PostHogFlags::REMOTE, fn (): Client => $this->clients->remote(), $scopes);

        return [
            'local' => $local,
            'remote' => $remote,
            'agreement' => $local === null ? [] : $this->agreement($local['values'], $remote['values']),
            'definitions' => [
                'cached' => $definitionsWereCached,
                'flags' => count($this->cache->get(CachedFlagDefinitions::KEY)['flags'] ?? []),
            ],
        ];
    }

    /**
     * @param  callable(): Client  $makeClient
     * @param  list<FlagScope>  $scopes
     * @return array<string, mixed>
     */
    private function measure(string $mode, callable $makeClient, array $scopes): array
    {
        $started = hrtime(true);
        $error = null;

        try {
            $client = $makeClient();
        } catch (Throwable $exception) {
            $client = null;
            $error = $exception->getMessage();
        }

        $setup = $this->since($started);
        $flags = new PostHogFlags(fn (): Client => $client ?? throw new \RuntimeException($error ?? 'No client'), $mode);

        $values = [];
        foreach ($scopes as $scope) {
            $values[$scope->distinctId] = $flags->evaluate($scope);
        }

        $timings = array_column($flags->timings(), 'milliseconds');

        return [
            'mode' => $mode,
            'setup_ms' => $setup,
            'timings' => $flags->timings(),
            'min_ms' => $timings === [] ? 0.0 : min($timings),
            'mean_ms' => $timings === [] ? 0.0 : round(array_sum($timings) / count($timings), 2),
            'max_ms' => $timings === [] ? 0.0 : max($timings),
            'total_ms' => round($setup + array_sum($timings), 2),
            'values' => $values,
            'error' => $error,
        ];
    }

    /**
     * Per visitor: did local and remote give the same answers?
     *
     * @param  array<string, array<string, bool|string>>  $local
     * @param  array<string, array<string, bool|string>>  $remote
     * @return array<string, bool>
     */
    private function agreement(array $local, array $remote): array
    {
        $result = [];

        foreach ($local as $distinctId => $flags) {
            $theirs = $remote[$distinctId] ?? [];
            ksort($flags);
            ksort($theirs);
            $result[$distinctId] = $flags === $theirs;
        }

        return $result;
    }

    private function since(int $started): float
    {
        return round((hrtime(true) - $started) / 1e6, 2);
    }
}
