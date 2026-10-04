<x-layouts.app title="Basket">
    @php($money = fn (int $pence) => \App\Support\Money::format($pence))

    <h1 class="text-2xl font-semibold tracking-tight">Your basket</h1>

    <section class="mt-6 rounded-lg border border-stone-200 bg-white p-6">
        <p class="text-xs uppercase tracking-wider text-stone-500">{{ $basket->fixture->competition->name }}</p>
        <a href="{{ route('fixtures.show', $basket->fixture) }}" class="mt-1 block text-lg font-semibold hover:underline">
            {{ $basket->fixture->title() }}
        </a>
        <p class="text-sm text-stone-600">{{ $basket->fixture->kicks_off_at->format('l j F, H:i') }}</p>

        <dl class="mt-6 space-y-3 text-sm">
            <div class="flex justify-between">
                <dt>{{ $basket->quantity }} &times; {{ $basket->ticketAllocation->stand->name }} ({{ $basket->ticketAllocation->stand->price_band->label() }})</dt>
                <dd class="font-medium">{{ $money($basket->ticketTotalPence()) }}</dd>
            </div>

            @if ($basket->travelOption)
                <div class="flex items-center justify-between">
                    <dt class="flex items-center gap-3">
                        <span>{{ $basket->quantity }} &times; coach from {{ $basket->travelOption->departure_point }}</span>
                        <form method="POST" action="{{ route('travel.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-700 hover:underline">Remove</button>
                        </form>
                    </dt>
                    <dd class="font-medium">{{ $money($basket->travelTotalPence()) }}</dd>
                </div>
            @endif

            <div class="flex justify-between border-t border-stone-200 pt-3 text-base">
                <dt class="font-semibold">Total</dt>
                <dd class="font-semibold">{{ $money($basket->totalPence()) }}</dd>
            </div>
        </dl>

        @guest
            <p class="mt-6 rounded-md bg-stone-100 px-4 py-3 text-sm text-stone-700">
                <a href="{{ route('login') }}" class="font-medium text-pitch-700 hover:underline">Log in</a>
                or <a href="{{ route('register') }}" class="font-medium text-pitch-700 hover:underline">create an account</a>
                to check out. Your basket is kept.
            </p>
        @endguest
    </section>

    @if ($travelOptions->isNotEmpty() && $basket->travelOption === null)
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Add away travel</h2>
            <p class="text-sm text-stone-600">One coach seat per ticket.</p>

            @error('travel_option_id')
                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <ul class="mt-3 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
                @foreach ($travelOptions as $travelOption)
                    <li class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-medium">{{ $travelOption->departure_point }}</p>
                            <p class="text-sm text-stone-500">
                                Departs {{ $travelOption->departs_at->format('D j M, H:i') }} &middot; {{ $travelOption->remaining() }} seats left
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <p class="font-semibold">{{ $money($travelOption->price_pence) }} pp</p>
                            @if ($travelOption->remaining() >= $basket->quantity)
                                <form method="POST" action="{{ route('travel.store') }}">
                                    @csrf
                                    <input type="hidden" name="travel_option_id" value="{{ $travelOption->id }}">
                                    <button type="submit" class="rounded-md bg-pitch-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-pitch-700">Add</button>
                                </form>
                            @else
                                <span class="text-xs text-red-700">Not enough seats</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
