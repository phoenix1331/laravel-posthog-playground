<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Fixture;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A visitor opened a fixture page.
 */
class FixtureViewed
{
    use Dispatchable, SerializesModels;

    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly Fixture $fixture,
        public readonly ?User $user,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }
}
