<ul data-seat-picker="list" class="mt-3 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
    @foreach ($allocations as $allocation)
        <li class="flex items-center justify-between gap-4 p-4">
            <div>
                <p class="font-medium">
                    {{ $allocation->stand->name }}
                    @if ($allocation->stand->is_away_end)
                        <span class="ml-1 text-xs font-normal text-stone-500">{{ $fixture->awayClub->name }} supporters</span>
                    @endif
                </p>
                <p class="text-sm text-stone-500">{{ $allocation->stand->price_band->label() }}</p>
            </div>

            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="font-semibold">{{ \App\Support\Money::format($allocation->stand->price_pence) }}</p>
                    <p class="text-xs">
                        @if ($allocation->isSoldOut())
                            <span class="text-red-700">Sold out</span>
                        @elseif ($allocation->isNearSellOut())
                            <span class="text-amber-700">Only {{ number_format($allocation->remaining()) }} left</span>
                        @else
                            <span class="text-stone-500">Available</span>
                        @endif
                    </p>
                </div>

                @unless ($allocation->isSoldOut())
                    <form method="POST" action="{{ route('seats.store', $fixture) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="ticket_allocation_id" value="{{ $allocation->id }}">
                        <label class="sr-only" for="quantity-{{ $allocation->id }}">Tickets</label>
                        <select id="quantity-{{ $allocation->id }}" name="quantity" class="rounded-md border border-stone-300 px-2 py-1.5 text-sm">
                            @foreach (range(1, min(\App\Models\Basket::MAX_TICKETS, $allocation->remaining())) as $quantity)
                                <option value="{{ $quantity }}">{{ $quantity }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md bg-pitch-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-pitch-700">Select</button>
                    </form>
                @endunless
            </div>
        </li>
    @endforeach
</ul>
