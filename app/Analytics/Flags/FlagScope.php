<?php

declare(strict_types=1);

namespace App\Analytics\Flags;

use App\Analytics\DistinctId;
use App\Analytics\Groups;
use App\Analytics\PersonProperties;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/**
 * Who a feature flag is evaluated for. Flags are scoped to the analytics
 * distinct ID rather than the user model, so anonymous visitors get a
 * variant too, and the same one PostHog would give them.
 *
 * Groups and person properties travel with the scope so PostHog can match
 * group- and property-based release conditions without a round trip.
 *
 * $at is when the flag was seen. It is null for live requests (now) and set
 * by the simulator so backdated journeys record exposure at the right time.
 * It is not part of the serialised scope: a visitor is the same person
 * whenever they are asked about.
 */
final readonly class FlagScope implements FeatureScopeSerializeable
{
    /**
     * @param  array<string, string>  $groups
     * @param  array<string, scalar>  $personProperties
     */
    public function __construct(
        public string $distinctId,
        public array $groups = [],
        public array $personProperties = [],
        public ?CarbonImmutable $at = null,
    ) {}

    /**
     * The visitor making the current request.
     */
    public static function current(): self
    {
        $user = Auth::user();

        return self::for($user instanceof User ? $user : null);
    }

    public static function for(?User $user, ?CarbonImmutable $at = null): self
    {
        if ($user === null) {
            return new self(app(DistinctId::class)->anonymous(), at: $at);
        }

        return new self(DistinctId::forUser($user), Groups::for($user), PersonProperties::for($user), $at);
    }

    /**
     * Accepts whatever Pennant was given as a scope: a FlagScope, a User,
     * a plain distinct ID string, or nothing (the current visitor).
     */
    public static function from(mixed $scope): self
    {
        return match (true) {
            $scope instanceof self => $scope,
            $scope instanceof User => self::for($scope),
            is_string($scope) && $scope !== '' => new self($scope),
            default => self::current(),
        };
    }

    public function featureScopeSerialize(): string
    {
        return $this->distinctId;
    }
}
