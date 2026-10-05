<x-layouts.app :title="$fixture->title()">
    <a href="{{ route('fixtures.index') }}" class="text-sm text-pitch-700 hover:underline">&larr; All fixtures</a>

    <header class="mt-4 rounded-lg bg-pitch-700 p-6 text-white">
        <p class="text-xs uppercase tracking-wider text-pitch-100">{{ $fixture->competition->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $fixture->title() }}</h1>
        <p class="mt-2 text-sm text-pitch-100">
            {{ $fixture->kicks_off_at->format('l j F, H:i') }} &middot;
            {{ $fixture->homeClub->stadium }}, {{ $fixture->homeClub->city }}
        </p>
    </header>

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Tickets</h2>

        @error('ticket_allocation_id')
            <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
        @enderror
        @error('quantity')
            <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
        @enderror

        @if ($newSeatPicker)
            @include('fixtures.partials.seat-map')
        @else
            @include('fixtures.partials.seat-list')
        @endif
    </section>

    @if ($travelOptions->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Away travel</h2>
            <p class="text-sm text-stone-600">Coaches for {{ $fixture->awayClub->name }} supporters. Add one from your basket after choosing tickets.</p>

            <ul class="mt-3 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
                @foreach ($travelOptions as $travelOption)
                    <li class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-medium">{{ $travelOption->departure_point }}</p>
                            <p class="text-sm text-stone-500">Departs {{ $travelOption->departs_at->format('D j M, H:i') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold">{{ \App\Support\Money::format($travelOption->price_pence) }}</p>
                            <p class="text-xs text-stone-500">{{ $travelOption->remaining() }} seats left</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
