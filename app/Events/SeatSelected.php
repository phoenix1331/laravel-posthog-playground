<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Basket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A visitor chose a stand and number of tickets.
 */
class SeatSelected
{
    use Dispatchable, SerializesModels;

    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly Basket $basket,
        public readonly ?User $user,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }
}
