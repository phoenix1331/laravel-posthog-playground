<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Decides who an analytics event belongs to. A logged-in supporter is their
 * user ID; anyone else gets an anonymous ID kept in the session, which is
 * shared with posthog-js so client and server events land on one person.
 */
class DistinctId
{
    public const string SESSION_KEY = 'analytics.anonymous_id';

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

        $id = $this->session->get(self::SESSION_KEY);

        if (is_string($id) && $id !== '') {
            return $id;
        }

        $id = (string) Str::uuid7();
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
}
