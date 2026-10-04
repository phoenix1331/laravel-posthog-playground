<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use App\Checkout\CheckoutFailed;
use App\Checkout\FakePaymentGateway;
use App\Checkout\PlaceBooking;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CheckoutController extends Controller
{
    public function show(CurrentBasket $basket): View
    {
        return view('checkout.show', [
            'basket' => $basket->get(),
            'testCard' => FakePaymentGateway::SUCCESS_CARD,
        ]);
    }

    public function store(CheckoutRequest $request, CurrentBasket $basket, PlaceBooking $placeBooking): RedirectResponse
    {
        try {
            $booking = $placeBooking->handle($basket->get(), $request->user(), $request->string('card_number')->toString());
        } catch (CheckoutFailed $failure) {
            return back()->withInput($request->except('card_number'))->withErrors([$failure->field => $failure->getMessage()]);
        }

        $basket->forget();

        return redirect()->route('bookings.show', $booking);
    }
}
