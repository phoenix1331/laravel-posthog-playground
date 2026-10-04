<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Basket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A supporter reached checkout and then went quiet for long enough that
 * the basket counts as abandoned. occurredAt is when it crossed that line,
 * not when the scheduled check noticed.
 */
class CheckoutAbandoned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Basket $basket,
        public readonly User $user,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
