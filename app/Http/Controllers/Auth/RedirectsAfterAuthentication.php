<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Basket\CurrentBasket;
use Illuminate\Http\RedirectResponse;

trait RedirectsAfterAuthentication
{
    /**
     * Back to wherever auth interrupted (checkout), else the basket if the
     * visitor has one, else the fixture list.
     */
    protected function redirectAfterAuthentication(CurrentBasket $basket): RedirectResponse
    {
        $fallback = $basket->get() === null ? route('fixtures.index') : route('basket.show');

        return redirect()->intended($fallback);
    }
}
