<?php

declare(strict_types=1);

namespace App\Checkout;

/**
 * Stands in for a card processor. No card data is stored or sent anywhere;
 * one test number is declined so the funnel has a payment failure path.
 */
class FakePaymentGateway
{
    public const string SUCCESS_CARD = '4242424242424242';

    public const string DECLINED_CARD = '4000000000000002';

    /**
     * @throws CheckoutFailed when the card is declined
     */
    public function charge(string $cardNumber, int $amountPence): void
    {
        if ($cardNumber === self::DECLINED_CARD) {
            throw CheckoutFailed::paymentDeclined();
        }
    }
}
