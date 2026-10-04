<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function show(Request $request, Booking $booking): View
    {
        // 404 rather than 403 so references cannot be probed
        abort_unless($booking->user()->is($request->user()), 404);

        $booking->load(['fixture.homeClub', 'fixture.awayClub', 'fixture.competition', 'ticketAllocation.stand', 'travelOption']);

        return view('bookings.show', ['booking' => $booking]);
    }
}
