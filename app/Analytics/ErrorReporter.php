<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Sends exceptions Laravel reports to PostHog error tracking, linked to the
 * person and, when the browser sent one, the posthog-js session so the
 * error sits next to its session replay.
 *
 * Called from withExceptions() in bootstrap/app.php for every exception
 * Laravel decides to report (validation errors, 404s and the like are
 * already filtered out). It never throws: a broken error reporter must not
 * hide the original error.
 */
class ErrorReporter
{
    /** Errors outside a browser request belong to the app, not a person */
    public const string SERVER_DISTINCT_ID = 'matchday-server';

    public function __construct(
        private readonly Analytics $analytics,
        private readonly DistinctId $distinctId,
    ) {}

    public function report(Throwable $exception): void
    {
        if ($this->wouldLoop($exception)) {
            return;
        }

        try {
            $this->analytics->captureException($this->who(), $exception, $this->context());
        } catch (Throwable) {
            // Laravel still logs the original exception
        }
    }

    /**
     * Failing to deliver analytics must not be reported to analytics: if
     * PostHog is down each report would queue another failing delivery.
     * CaptureAnalyticsEvent wraps every delivery error in this type.
     */
    private function wouldLoop(Throwable $exception): bool
    {
        return $exception instanceof AnalyticsDeliveryFailed;
    }

    private function who(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::SERVER_DISTINCT_ID;
        }

        $user = Auth::user();

        return $this->distinctId->for($user instanceof User ? $user : null);
    }

    /**
     * Where it happened. Paths and route names only: query strings can carry
     * tokens or personal data.
     *
     * @return array<string, scalar|null>
     */
    private function context(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [
                '$process_person_profile' => false,
                'environment' => app()->environment(),
                'context' => 'console',
            ];
        }

        $request = request();

        return array_filter([
            '$session_id' => PostHogSession::id($request),
            '$pathname' => '/'.ltrim($request->path(), '/'),
            'http_method' => $request->method(),
            'route' => $request->route()?->getName(),
            'environment' => app()->environment(),
            'context' => 'http',
        ], fn (mixed $value): bool => $value !== null);
    }
}
