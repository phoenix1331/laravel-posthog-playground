<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    public static function format(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }
}
