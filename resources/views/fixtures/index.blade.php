<x-layouts.app title="Fixtures">
    <h1 class="text-2xl font-semibold tracking-tight">Upcoming fixtures</h1>
    <p class="mt-1 text-sm text-stone-600">Pick a match, choose your stand and add a coach for away days.</p>

    @forelse ($fixturesByDate as $date => $fixtures)
        <section class="mt-8">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                {{ \Illuminate\Support\Carbon::parse($date)->format('l j F') }}
            </h2>

            <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($fixtures as $fixture)
                    @php($fromPrice = $fixture->cheapestAvailablePricePence())
                    <li>
                        <a href="{{ route('fixtures.show', $fixture) }}"
                           class="block rounded-lg border border-stone-200 bg-white p-4 shadow-sm transition hover:border-pitch-600 hover:shadow">
                            <div class="flex items-center justify-between text-xs text-stone-500">
                                <span>{{ $fixture->competition->name }}</span>
                                <span>{{ $fixture->kicks_off_at->format('H:i') }}</span>
                            </div>
                            <p class="mt-2 font-semibold">{{ $fixture->title() }}</p>
                            <p class="text-sm text-stone-600">{{ $fixture->homeClub->stadium }}, {{ $fixture->homeClub->city }}</p>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <span class="font-medium text-pitch-700">
                                    {{ $fromPrice === null ? 'Sold out' : 'From '.\App\Support\Money::format($fromPrice) }}
                                </span>
                                @if ($fixture->isSellingFast())
                                    <span class="rounded-full bg-floodlight/20 px-2 py-0.5 text-xs font-medium text-amber-800">Selling fast</span>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="mt-8 text-stone-600">No upcoming fixtures. Run <code>make fresh</code> to seed some.</p>
    @endforelse
</x-layouts.app>
