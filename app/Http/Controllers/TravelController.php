<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use App\Events\TravelAdded;
use App\Http\Requests\AddTravelRequest;
use Illuminate\Http\RedirectResponse;

class TravelController extends Controller
{
    public function store(AddTravelRequest $request, CurrentBasket $basket): RedirectResponse
    {
        $updated = $basket->addTravel($request->basket(), $request->travelOption());

        TravelAdded::dispatch($updated, $request->user());

        return redirect()->route('basket.show');
    }

    public function destroy(CurrentBasket $basket): RedirectResponse
    {
        $basket->removeTravel($basket->get());

        return redirect()->route('basket.show');
    }
}
