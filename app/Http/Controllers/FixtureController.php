<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fixture;
use App\Models\TicketAllocation;
use Illuminate\Contracts\View\View;

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

    public function show(Fixture $fixture): View
    {
        $fixture->load(['competition', 'homeClub', 'awayClub', 'ticketAllocations.stand', 'travelOptions']);

        $allocations = $fixture->ticketAllocations
            ->sortByDesc(fn (TicketAllocation $allocation) => $allocation->stand->price_pence)
            ->values();

        return view('fixtures.show', [
            'fixture' => $fixture,
            'allocations' => $allocations,
            'travelOptions' => $fixture->travelOptions->sortBy('departs_at')->values(),
        ]);
    }
}
