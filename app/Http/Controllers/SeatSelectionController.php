<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use App\Events\SeatSelected;
use App\Http\Requests\SelectSeatsRequest;
use App\Models\Fixture;
use Illuminate\Http\RedirectResponse;

class SeatSelectionController extends Controller
{
    public function store(SelectSeatsRequest $request, Fixture $fixture, CurrentBasket $basket): RedirectResponse
    {
        $selected = $basket->selectSeats($request->allocation(), $request->integer('quantity'));

        SeatSelected::dispatch($selected, $request->user());

        return redirect()->route('basket.show');
    }
}
