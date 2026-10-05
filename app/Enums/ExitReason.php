<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Answers to the checkout exit survey: why a supporter is leaving
 * without paying.
 */
enum ExitReason: string
{
    case TooExpensive = 'too_expensive';
    case WrongSeats = 'wrong_seats';
    case TravelOptions = 'travel_options';
    case PaymentConcerns = 'payment_concerns';
    case JustBrowsing = 'just_browsing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TooExpensive => 'Tickets cost more than I expected',
            self::WrongSeats => 'I could not get the seats I wanted',
            self::TravelOptions => 'The coach times did not suit me',
            self::PaymentConcerns => 'I was not sure about paying online',
            self::JustBrowsing => 'I was just looking',
            self::Other => 'Something else',
        };
    }
}
