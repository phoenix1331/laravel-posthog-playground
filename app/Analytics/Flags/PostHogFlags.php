<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use Closure;
use PostHog\Client;
use Throwable;

/**
 * Evaluates every PostHog flag for a scope, once per request.
 *
 * Local evaluation (a secret key, definitions cached by
 * CachedFlagDefinitions) computes flags in PHP with no network call; remote
 * evaluation asks PostHog's /flags endpoint for each new scope. Timings are
 * recorded so the difference can be shown on the debug page.
 *
 * getAllFlags() is used rather than evaluateFlags() because the latter sends
 * $feature_flag_called through the SDK's own client inside the request;
 * exposure is captured through the queued Analytics contract instead.
 */
class PostHogFlags
{
    public const string LOCAL = 'local';

    public const string REMOTE = 'remote';

    private ?Client $client = null;

    /** @var array<string, array<string, bool|string>> distinct ID => flag values */
    private array $evaluated = [];

    /** @var list<array{distinct_id: string, mode: string, milliseconds: float, flags: int}> */
    private array $timings = [];

    /**
     * @param  Closure(): Client  $makeClient  built on first use, so pages that never touch a flag pay nothing
     */
    public function __construct(
        private readonly Closure $makeClient,
        public readonly string $mode,
    ) {}

    /**
     * @return array<string, bool|string> flag key => false, true or a variant key
     */
    public function evaluate(FlagScope $scope): array
    {
        if (array_key_exists($scope->distinctId, $this->evaluated)) {
            return $this->evaluated[$scope->distinctId];
        }

        $started = hrtime(true);

        try {
            $flags = $this->client()->getAllFlags(
                $scope->distinctId,
                $scope->groups,
                $scope->personProperties,
            );
        } catch (Throwable $exception) {
            report($exception);
            $flags = [];
        }

        $this->timings[] = [
            'distinct_id' => $scope->distinctId,
            'mode' => $this->mode,
            'milliseconds' => round((hrtime(true) - $started) / 1e6, 2),
            'flags' => count($flags),
        ];

        return $this->evaluated[$scope->distinctId] = $flags;
    }

    /**
     * @return list<array{distinct_id: string, mode: string, milliseconds: float, flags: int}>
     */
    public function timings(): array
    {
        return $this->timings;
    }

    private function client(): Client
    {
        return $this->client ??= ($this->makeClient)();
    }
}
