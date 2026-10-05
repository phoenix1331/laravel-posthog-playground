<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use Illuminate\Contracts\Cache\Repository;
use PostHog\FlagDefinitionCacheProvider;

/**
 * Shares PostHog flag definitions between PHP workers through Laravel's
 * cache. Without this every request would build a client that downloads
 * every definition before evaluating a single flag, which is slower than
 * just asking PostHog.
 *
 * One worker refreshes once the cached copy expires; a short lock stops a
 * stampede of workers all fetching at the same moment.
 */
final class CachedFlagDefinitions implements FlagDefinitionCacheProvider
{
    public const string KEY = 'posthog:flag-definitions';

    private const string LOCK_KEY = 'posthog:flag-definitions:refreshing';

    private const int LOCK_SECONDS = 30;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttlSeconds,
    ) {}

    public function getFlagDefinitions(): ?array
    {
        $definitions = $this->cache->get(self::KEY);

        return is_array($definitions) ? $definitions : null;
    }

    public function shouldFetchFlagDefinitions(): bool
    {
        if ($this->cache->has(self::KEY)) {
            return false;
        }

        return $this->cache->add(self::LOCK_KEY, true, self::LOCK_SECONDS);
    }

    public function onFlagDefinitionsReceived(array $data): void
    {
        $this->cache->put(self::KEY, $data, $this->ttlSeconds);
        $this->cache->forget(self::LOCK_KEY);
    }

    public function shutdown(): void {}
}
