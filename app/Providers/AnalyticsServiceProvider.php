<?php

declare(strict_types=1);

namespace App\Providers;

use App\Analytics\Analytics;
use App\Analytics\NullAnalytics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Analytics::class, fn (): Analytics => $this->nullAnalytics());
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
