<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\Cache\Repository;
use PostHog\Client;

/**
 * Builds PostHog clients for flag evaluation, kept apart from the capture
 * client the queue worker uses.
 *
 * - local: secret key plus the shared definitions cache, so flags are
 *   computed in PHP and PostHog is only asked for definitions once per TTL
 * - remote: no secret key, so every new visitor costs a /flags request
 */
class FlagClientFactory
{
    public function __construct(private readonly Repository $cache) {}

    public function forConfiguredMode(): Client
    {
        return $this->localAvailable() && config('posthog.flags.evaluation') === PostHogFlags::LOCAL
            ? $this->local()
            : $this->remote();
    }

    public function configuredMode(): string
    {
        return $this->localAvailable() && config('posthog.flags.evaluation') === PostHogFlags::LOCAL
            ? PostHogFlags::LOCAL
            : PostHogFlags::REMOTE;
    }

    /**
     * Local evaluation needs the secret key to download definitions.
     */
    public function localAvailable(): bool
    {
        return filled(config('posthog.secret_key'));
    }

    public function local(): Client
    {
        return new Client(
            apiKey: config('posthog.project_token'),
            options: [
                ...$this->options(),
                'flag_definition_cache_provider' => new CachedFlagDefinitions($this->cache, config('posthog.flags.definitions_ttl')),
            ],
            loadFeatureFlags: true,
            secretKey: config('posthog.secret_key'),
        );
    }

    public function remote(): Client
    {
        return new Client(
            apiKey: config('posthog.project_token'),
            options: $this->options(),
            loadFeatureFlags: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return AnalyticsServiceProvider::clientOptions();
    }
}
