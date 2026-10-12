<?php

declare(strict_types=1);

namespace App\Simulation;

use App\Enums\ExitReason;

/**
 * Kinds of simulated supporter. Each has a share of traffic and its own
 * odds of moving to the next funnel step, which together give the funnel
 * realistic drop-off.
 */
enum Persona: string
{
    /** Window-shops a few fixtures, rarely commits */
    case Browser = 'browser';

    /** Compares several fixtures and always picks the cheapest seats */
    case BargainHunter = 'bargain_hunter';

    /** Follows their club away, sits in the away end and takes the coach */
    case LoyalAwayFan = 'loyal_away_fan';

    /** Gets to checkout and walks away */
    case Abandoner = 'abandoner';

    /**
     * Relative share of simulated supporters.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Browser => 45,
            self::BargainHunter => 25,
            self::LoyalAwayFan => 15,
            self::Abandoner => 15,
        };
    }

    /**
     * @return array{int, int} minimum and maximum fixtures viewed
     */
    public function fixturesViewed(): array
    {
        return match ($this) {
            self::Browser => [1, 3],
            self::BargainHunter => [2, 5],
            self::LoyalAwayFan => [1, 2],
            self::Abandoner => [1, 3],
        };
    }

    /**
     * @return array{int, int} minimum and maximum tickets
     */
    public function tickets(): array
    {
        return match ($this) {
            self::Browser => [1, 2],
            self::BargainHunter => [1, 4],
            self::LoyalAwayFan => [1, 3],
            self::Abandoner => [1, 2],
        };
    }

    /**
     * Chance (0-100) of selecting seats after viewing.
     */
    public function selectsSeats(): int
    {
        return match ($this) {
            self::Browser => 25,
            self::BargainHunter => 60,
            self::LoyalAwayFan => 90,
            self::Abandoner => 80,
        };
    }

    /**
     * Chance (0-100) of adding a coach once seats are selected.
     */
    public function addsTravel(): int
    {
        return match ($this) {
            self::Browser => 10,
            self::BargainHunter => 5,
            self::LoyalAwayFan => 80,
            self::Abandoner => 20,
        };
    }

    /**
     * Chance (0-100) of starting checkout once seats are selected.
     */
    public function startsCheckout(): int
    {
        return match ($this) {
            self::Browser => 40,
            self::BargainHunter => 55,
            self::LoyalAwayFan => 85,
            self::Abandoner => 90,
        };
    }

    /**
     * Chance (0-100) of paying once at checkout. Anyone who does not pay
     * abandons the checkout.
     */
    public function purchases(): int
    {
        return match ($this) {
            self::Browser => 45,
            self::BargainHunter => 60,
            self::LoyalAwayFan => 90,
            self::Abandoner => 0,
        };
    }

    /**
     * Relative likelihood of each exit survey answer when this persona
     * leaves checkout, so the survey results tell a story per persona.
     *
     * @return array<string, int> ExitReason value => weight
     */
    public function exitReasons(): array
    {
        return match ($this) {
            self::Browser => [
                ExitReason::JustBrowsing->value => 50,
                ExitReason::TooExpensive->value => 20,
                ExitReason::WrongSeats->value => 15,
                ExitReason::Other->value => 15,
            ],
            self::BargainHunter => [
                ExitReason::TooExpensive->value => 60,
                ExitReason::WrongSeats->value => 20,
                ExitReason::JustBrowsing->value => 10,
                ExitReason::Other->value => 10,
            ],
            self::LoyalAwayFan => [
                ExitReason::TravelOptions->value => 55,
                ExitReason::WrongSeats->value => 25,
                ExitReason::PaymentConcerns->value => 10,
                ExitReason::Other->value => 10,
            ],
            self::Abandoner => [
                ExitReason::PaymentConcerns->value => 40,
                ExitReason::TooExpensive->value => 30,
                ExitReason::TravelOptions->value => 15,
                ExitReason::Other->value => 15,
            ],
        };
    }
}
