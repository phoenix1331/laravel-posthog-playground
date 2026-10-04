<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use App\Http\Requests\SelectSeatsRequest;
use App\Models\Fixture;
use Illuminate\Http\RedirectResponse;

class SeatSelectionController extends Controller
{
    public function store(SelectSeatsRequest $request, Fixture $fixture, CurrentBasket $basket): RedirectResponse
    {
        $basket->selectSeats($request->allocation(), $request->integer('quantity'));

        return redirect()->route('basket.show');
    }
}
