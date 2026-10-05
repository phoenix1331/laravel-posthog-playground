@php
    $soldOut = $allocation->isSoldOut();
    $tone = match (true) {
        $soldOut => 'border-stone-200 bg-stone-100 text-stone-400',
        $allocation->isNearSellOut() => 'border-amber-300 bg-amber-50 peer-checked:border-amber-600 peer-checked:ring-2 peer-checked:ring-amber-500',
        default => 'border-pitch-100 bg-pitch-50 peer-checked:border-pitch-600 peer-checked:ring-2 peer-checked:ring-pitch-600',
    };
@endphp

<label class="{{ $position }} block {{ $soldOut ? 'cursor-not-allowed' : 'cursor-pointer' }}">
    <input type="radio" name="ticket_allocation_id" value="{{ $allocation->id }}" class="peer sr-only"
           @disabled($soldOut) @checked(old('ticket_allocation_id') == $allocation->id) required>
    <span class="flex h-full flex-col justify-center rounded-md border p-3 text-center text-sm transition {{ $tone }}">
        <span class="font-medium">{{ $allocation->stand->name }}</span>
        @if ($allocation->stand->is_away_end)
            <span class="text-xs">{{ $fixture->awayClub->short_name }} fans</span>
        @endif
        <span class="mt-1 font-semibold">{{ \App\Support\Money::format($allocation->stand->price_pence) }}</span>
        <span class="text-xs">
            @if ($soldOut)
                Sold out
            @elseif ($allocation->isNearSellOut())
                {{ number_format($allocation->remaining()) }} left
            @else
                {{ $allocation->stand->price_band->label() }}
            @endif
        </span>
    </span>
</label>
