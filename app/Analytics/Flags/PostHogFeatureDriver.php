<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use App\Analytics\Analytics;
use App\Analytics\EventProperties;
use App\Analytics\Events;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\Cache\Repository;
use Laravel\Pennant\Contracts\DefinesFeaturesExternally;
use Laravel\Pennant\Contracts\Driver;
use LogicException;

/**
 * A Laravel Pennant store backed by PostHog feature flags. Application code
 * calls Feature::active() and never learns PostHog is the source.
 *
 * - Values come from PostHog: false, true, or a variant key for
 *   multivariate flags
 * - Features defined in the app are fallbacks, used when PostHog is not
 *   configured or does not know the flag, so the app runs without an account
 * - Each new answer per person is recorded as $feature_flag_called, through
 *   the queued Analytics contract, which is what PostHog experiments count as
 *   exposure
 * - PostHog owns rollout, so writing flag values from Laravel is refused
 */
class PostHogFeatureDriver implements DefinesFeaturesExternally, Driver
{
    /** @var array<string, callable(mixed): mixed> */
    private array $fallbacks = [];

    public function __construct(
        private readonly PostHogFlags $flags,
        private readonly Analytics $analytics,
        private readonly Repository $cache,
        private readonly int $exposureTtlSeconds,
    ) {}

    public function define(string $feature, callable $resolver): void
    {
        $this->fallbacks[$feature] = $resolver;
    }

    /**
     * @return list<string>
     */
    public function defined(): array
    {
        return array_keys($this->fallbacks);
    }

    /**
     * Flags PostHog knows for this scope plus local fallbacks.
     *
     * @return list<string>
     */
    public function definedFeaturesForScope(mixed $scope): array
    {
        $remote = AnalyticsServiceProvider::sending() ? array_keys($this->flags->evaluate(FlagScope::from($scope))) : [];

        return array_values(array_unique([...$remote, ...$this->defined()]));
    }

    /**
     * @param  array<string, list<mixed>>  $features
     * @return array<string, list<mixed>>
     */
    public function getAll(array $features): array
    {
        return collect($features)
            ->map(fn (array $scopes, string $feature): array => array_map(fn (mixed $scope): mixed => $this->get($feature, $scope), $scopes))
            ->all();
    }

    public function get(string $feature, mixed $scope): mixed
    {
        $flagScope = FlagScope::from($scope);

        if (! AnalyticsServiceProvider::sending()) {
            return $this->fallback($feature, $flagScope);
        }

        $flags = $this->flags->evaluate($flagScope);

        if (! array_key_exists($feature, $flags)) {
            return $this->fallback($feature, $flagScope);
        }

        $this->recordExposure($feature, $flags[$feature], $flagScope);

        return $flags[$feature];
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        throw self::managedInPostHog();
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        throw self::managedInPostHog();
    }

    public function delete(string $feature, mixed $scope): void
    {
        throw self::managedInPostHog();
    }

    /**
     * @param  list<string>|null  $features
     */
    public function purge(?array $features): void
    {
        throw self::managedInPostHog();
    }

    /**
     * Fallback resolvers always receive a FlagScope, whatever Pennant was
     * given, so they can be written once: fn (FlagScope $scope) => ...
     */
    private function fallback(string $feature, FlagScope $scope): mixed
    {
        $resolver = $this->fallbacks[$feature] ?? null;

        return $resolver === null ? false : $resolver($scope);
    }

    /**
     * Once per person, flag and value within the TTL: PostHog only needs to
     * know someone saw a variant, not every page view on which they saw it.
     */
    private function recordExposure(string $feature, bool|string $value, FlagScope $scope): void
    {
        $key = 'posthog:exposure:'.hash('sha256', json_encode([$feature, $scope->distinctId, $value], JSON_THROW_ON_ERROR));

        if (! $this->cache->add($key, true, $this->exposureTtlSeconds)) {
            return;
        }

        $this->analytics->capture(
            distinctId: $scope->distinctId,
            event: Events::FEATURE_FLAG_CALLED,
            properties: EventProperties::make()->featureFlag($feature, $value)->toArray(),
            groups: $scope->groups,
        );
    }

    private static function managedInPostHog(): LogicException
    {
        return new LogicException('Feature flags in the posthog store are managed in PostHog, not written from Laravel.');
    }
}
