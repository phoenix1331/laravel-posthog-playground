<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceBand: string
{
    case Premium = 'premium';
    case Standard = 'standard';
    case Value = 'value';

    public function label(): string
    {
        return match ($this) {
            self::Premium => 'Premium',
            self::Standard => 'Standard',
            self::Value => 'Value',
        };
    }
}
