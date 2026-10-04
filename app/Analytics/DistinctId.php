<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Decides who an analytics event belongs to. A logged-in supporter is their
 * user ID; anyone else is anonymous.
 *
 * The anonymous ID is shared with posthog-js in both directions: the server
 * bootstraps the browser with it on a first visit, and once the browser has
 * its own PostHog cookie the server adopts the ID from there. The cookie
 * outlives the session, so this keeps client and server events on one
 * person even after the session expires.
 */
class DistinctId
{
    public const string SESSION_KEY = 'analytics.anonymous_id';

    private const int MAX_LENGTH = 200;

    private ?string $override = null;

    public function __construct(private readonly Session $session) {}

    public function for(?User $user): string
    {
        if ($user !== null) {
            return self::forUser($user);
        }

        return $this->anonymous();
    }

    public static function forUser(User $user): string
    {
        return (string) $user->id;
    }

    public function anonymous(): string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $id = $this->fromPostHogCookie() ?? $this->session->get(self::SESSION_KEY);

        if (! is_string($id) || $id === '') {
            $id = (string) Str::uuid7();
        }

        $this->session->put(self::SESSION_KEY, $id);

        return $id;
    }

    /**
     * Act as a given anonymous visitor outside a browser session, e.g. a
     * simulated visitor in matchday:simulate.
     */
    public function useAnonymousId(string $id): void
    {
        $this->override = $id;
    }

    /**
     * posthog-js persists to "ph_<project token>_posthog". It must be left
     * unencrypted for the server to read it (see AnalyticsServiceProvider).
     */
    public static function postHogCookieName(): ?string
    {
        $token = config('posthog.project_token');

        return filled($token) ? "ph_{$token}_posthog" : null;
    }

    private function fromPostHogCookie(): ?string
    {
        $name = self::postHogCookieName();

        if ($name === null) {
            return null;
        }

        // Read the request at call time: this object can outlive the request it
        // was built in (the event subscriber is first resolved at boot)
        $state = json_decode((string) request()->cookies->get($name), true);
        $id = is_array($state) ? ($state['distinct_id'] ?? null) : null;

        if (! is_string($id) || $id === '' || strlen($id) > self::MAX_LENGTH) {
            return null;
        }

        return $id;
    }
}
