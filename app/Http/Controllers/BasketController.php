<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use Illuminate\Contracts\View\View;

class BasketController extends Controller
{
    public function show(CurrentBasket $currentBasket): View
    {
        $basket = $currentBasket->get();

        return view('basket.show', [
            'basket' => $basket,
            'travelOptions' => $basket->fixture->travelOptions()->orderBy('departs_at')->get(),
        ]);
    }
}
