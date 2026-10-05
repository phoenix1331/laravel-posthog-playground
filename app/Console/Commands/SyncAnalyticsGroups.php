<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Analytics;
use App\Analytics\Groups;
use App\Models\SupportersClub;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('matchday:sync-groups')]
#[Description('Send every supporters club to PostHog as a group with its properties')]
class SyncAnalyticsGroups extends Command
{
    public function handle(Analytics $analytics): int
    {
        $supportersClubs = SupportersClub::with('club')->orderBy('name')->get();

        foreach ($supportersClubs as $supportersClub) {
            $analytics->groupIdentify(Groups::SUPPORTERS_CLUB, $supportersClub->slug, Groups::properties($supportersClub));
        }

        $this->components->info("Synced {$supportersClubs->count()} supporters clubs as \"".Groups::SUPPORTERS_CLUB.'" groups.');

        return self::SUCCESS;
    }
}
