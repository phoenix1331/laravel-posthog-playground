<?php

declare(strict_types=1);

namespace App\Features;

use App\Analytics\Events;
use App\Analytics\Experiment;
use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\Rollout;
use App\Support\Money;

/**
 * Checkout button experiment. A multivariate PostHog flag "checkout-cta"
 * with variants "control" and "test", split 50/50, with ticket_purchased as
 * the goal metric.
 *
 * The variant is chosen on the server and rendered into the page, so the
 * button never flickers from one label to the other after load.
 *
 * resolve() is the fallback when PostHog is not configured or does not
 * know the flag, and reproduces PostHog's own variant assignment.
 */
class CheckoutCta implements Experiment
{
    public string $name = 'checkout-cta';

    public const string CONTROL = 'control';

    public const string TEST = 'test';

    /** @var array<string, int> */
    public const array VARIANTS = [self::CONTROL => 50, self::TEST => 50];

    public function resolve(FlagScope $scope): string
    {
        return Rollout::variant($this->name, $scope->distinctId, self::VARIANTS) ?? self::CONTROL;
    }

    /**
     * Button text for a variant. Anything unexpected (a flag turned off, an
     * unknown variant) gets the control copy.
     */
    public static function label(mixed $variant, int $totalPence): string
    {
        $amount = Money::format($totalPence);

        return match ($variant) {
            self::TEST => "Secure my seats for {$amount}",
            default => "Pay {$amount}",
        };
    }

    public static function key(): string
    {
        return 'checkout-cta';
    }

    /**
     * Only events from people who have seen the button: tagging earlier
     * steps would evaluate the flag, and so record exposure, for visitors
     * who never reached checkout.
     *
     * @return list<string>
     */
    public static function taggedEvents(): array
    {
        return [Events::CHECKOUT_STARTED, Events::TICKET_PURCHASED, Events::CHECKOUT_ABANDONED];
    }
}
