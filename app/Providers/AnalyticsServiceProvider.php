<?php

declare(strict_types=1);

namespace App\Providers;

use App\Analytics\Analytics;
use App\Analytics\DistinctId;
use App\Analytics\NullAnalytics;
use App\Analytics\PostHogAnalytics;
use App\Listeners\AnalyticsEventSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
        Event::subscribe(AnalyticsEventSubscriber::class);
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
