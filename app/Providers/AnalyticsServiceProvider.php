<?php

declare(strict_types=1);

namespace App\Providers;

use App\Analytics\Analytics;
use App\Analytics\DistinctId;
use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\PostHogFeatureDriver;
use App\Analytics\Flags\PostHogFlags;
use App\Analytics\NullAnalytics;
use App\Analytics\PostHogAnalytics;
use App\Listeners\AnalyticsEventSubscriber;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use PostHog\Client;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Analytics::class, function (): Analytics {
            if (! self::sending()) {
                return $this->nullAnalytics();
            }

            return new PostHogAnalytics;
        });

        // Used by the queue worker to deliver capture jobs. Flag definitions
        // are not loaded here: capture never evaluates flags, and loading
        // them would cost an HTTP call whenever a secret key is set.
        $this->app->singleton(Client::class, fn (): Client => new Client(
            apiKey: config('posthog.project_token'),
            options: ['host' => config('posthog.host'), ...config('posthog.client')],
            loadFeatureFlags: false,
        ));

        // Per request: the anonymous ID lives in that request's session
        $this->app->scoped(DistinctId::class);

        // Per request so evaluated flags and timings never leak between requests
        $this->app->scoped(PostHogFlags::class, fn (): PostHogFlags => new PostHogFlags(
            makeClient: fn (): Client => $this->flagClient(),
            mode: self::localEvaluation() ? PostHogFlags::LOCAL : PostHogFlags::REMOTE,
        ));
    }

    public function boot(): void
    {
        Event::subscribe(AnalyticsEventSubscriber::class);

        self::exemptPostHogCookie();

        Feature::extend('posthog', fn (Application $app): PostHogFeatureDriver => new PostHogFeatureDriver(
            flags: $app->make(PostHogFlags::class),
            analytics: $app->make(Analytics::class),
            cache: $app->make('cache.store'),
            exposureTtlSeconds: config('posthog.flags.exposure_ttl'),
        ));

        // Flags are scoped to the analytics distinct ID, so anonymous visitors
        // get a variant and keep it when they log in (after aliasing)
        Feature::resolveScopeUsing(fn (): FlagScope => FlagScope::current());
    }

    /**
     * Local evaluation needs the secret key to download flag definitions.
     */
    public static function localEvaluation(): bool
    {
        return config('posthog.flags.evaluation') === PostHogFlags::LOCAL && filled(config('posthog.secret_key'));
    }

    /**
     * A client for flags only, separate from the capture client used by the
     * queue worker. In local mode it loads definitions from the shared cache
     * (downloading them at most once per TTL); in remote mode it has no
     * secret key, so every evaluation goes to /flags.
     */
    private function flagClient(): Client
    {
        $local = self::localEvaluation();
        $options = ['host' => config('posthog.host'), ...config('posthog.client')];

        if ($local) {
            $options['flag_definition_cache_provider'] = new CachedFlagDefinitions(
                $this->app->make('cache.store'),
                config('posthog.flags.definitions_ttl'),
            );
        }

        return new Client(
            apiKey: config('posthog.project_token'),
            options: $options,
            loadFeatureFlags: $local,
            secretKey: $local ? config('posthog.secret_key') : null,
        );
    }

    /**
     * posthog-js writes its cookie as plain JSON; leave it unencrypted so
     * DistinctId can adopt the browser's anonymous ID.
     */
    public static function exemptPostHogCookie(): void
    {
        $cookie = DistinctId::postHogCookieName();

        if ($cookie !== null) {
            EncryptCookies::except($cookie);
        }
    }

    /**
     * Events leave the app only with a project token and the kill switch
     * off. Anything else runs on the null driver, so the app works without
     * a PostHog account.
     */
    public static function sending(): bool
    {
        return ! config('posthog.disabled') && filled(config('posthog.project_token'));
    }

    /**
     * Logs each call when POSTHOG_LOG_CHANNEL names a log channel, otherwise
     * drops it silently.
     */
    private function nullAnalytics(): NullAnalytics
    {
        $channel = config('posthog.log_channel');

        return new NullAnalytics(filled($channel) ? Log::channel($channel) : null);
    }
}
