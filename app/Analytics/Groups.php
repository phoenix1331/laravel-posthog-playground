<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Models\SupportersClub;
use App\Models\User;

/**
 * PostHog group analytics. Supporters' clubs are a group type, so funnels
 * and trends can be broken down per club rather than only per person.
 */
final class Groups
{
    public const string SUPPORTERS_CLUB = 'supporters_club';

    /**
     * Groups to attach to an event for this user.
     *
     * @return array<string, string> group type => group key
     */
    public static function for(?User $user): array
    {
        $supportersClub = $user?->supportersClub;

        if ($supportersClub === null) {
            return [];
        }

        return [self::SUPPORTERS_CLUB => $supportersClub->slug];
    }

    /**
     * Properties describing a supporters' club group. A club's name is not
     * personal data; its members are only counted, never listed.
     *
     * @return array<string, scalar>
     */
    public static function properties(SupportersClub $supportersClub): array
    {
        return [
            'name' => $supportersClub->name,
            'club' => $supportersClub->club->slug,
            'members' => $supportersClub->members()->count(),
        ];
    }
}
