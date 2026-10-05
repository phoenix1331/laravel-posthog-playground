<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\FixtureViewed;
use App\Features\NewSeatPicker;
use App\Models\Fixture;
use App\Models\TicketAllocation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class FixtureController extends Controller
{
    public function index(): View
    {
        $fixtures = Fixture::upcoming()
            ->with(['competition', 'homeClub', 'awayClub', 'ticketAllocations.stand'])
            ->get()
            ->groupBy(fn (Fixture $fixture) => $fixture->kicks_off_at->toDateString());

        return view('fixtures.index', ['fixturesByDate' => $fixtures]);
    }

    public function show(Request $request, Fixture $fixture): View
    {
        $fixture->load(['competition', 'homeClub', 'awayClub', 'ticketAllocations.stand', 'travelOptions']);

        FixtureViewed::dispatch($fixture, $request->user());

        $allocations = $fixture->ticketAllocations
            ->sortByDesc(fn (TicketAllocation $allocation) => $allocation->stand->price_pence)
            ->values();

        return view('fixtures.show', [
            'fixture' => $fixture,
            'allocations' => $allocations,
            'newSeatPicker' => Feature::active(NewSeatPicker::class),
            'travelOptions' => $fixture->travelOptions->sortBy('departs_at')->values(),
        ]);
    }
}
