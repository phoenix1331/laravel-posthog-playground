<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\User;

/**
 * Properties set on a PostHog person when they identify. Same rule as
 * event properties: nothing that identifies a human (no name, no email),
 * only what is useful for segmenting supporters.
 */
final class PersonProperties
{
    /**
     * @return array<string, scalar>
     */
    public static function for(User $user): array
    {
        return array_filter([
            'supporters_club' => $user->supportersClub?->slug,
            'has_supporters_club' => $user->supporters_club_id !== null,
            'signed_up_at' => $user->created_at?->toDateString(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
