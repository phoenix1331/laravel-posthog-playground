<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ExitReason;
use App\Models\Basket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A supporter leaving checkout told us why.
 */
class CheckoutExitSurveyAnswered
{
    use Dispatchable, SerializesModels;

    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly Basket $basket,
        public readonly User $user,
        public readonly ExitReason $reason,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }
}
