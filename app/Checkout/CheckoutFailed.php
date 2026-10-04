<?php

declare(strict_types=1);

namespace App\Checkout;

use RuntimeException;

/**
 * A checkout that could not complete for a reason the supporter can act on.
 * The field names which form input the message belongs to.
 */
final class CheckoutFailed extends RuntimeException
{
    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function kickedOff(): self
    {
        return new self('basket', 'This fixture has already kicked off.');
    }

    public static function ticketsGone(): self
    {
        return new self('basket', 'Sorry, there are no longer enough tickets left in that stand.');
    }

    public static function coachFull(): self
    {
        return new self('basket', 'Sorry, there are no longer enough seats on that coach.');
    }

    public static function paymentDeclined(): self
    {
        return new self('card_number', 'Your card was declined. Try the test card 4242 4242 4242 4242.');
    }
}
