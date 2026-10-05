{{--
    Stadium-map seat picker, shown to visitors in the new-seat-picker rollout.
    Stands sit around the pitch where they are in the ground; pick one, choose
    how many tickets, and it posts to the same route as the list picker.
--}}
@php
    $positions = [
        'North Stand' => 'col-start-2 row-start-1',
        'Main Stand' => 'col-start-1 row-start-2',
        'Family Stand' => 'col-start-3 row-start-2',
        'Away End' => 'col-start-2 row-start-3',
    ];
    $placed = $allocations->filter(fn ($allocation) => isset($positions[$allocation->stand->name]));
    $unplaced = $allocations->reject(fn ($allocation) => isset($positions[$allocation->stand->name]));
@endphp

<form method="POST" action="{{ route('seats.store', $fixture) }}" data-seat-picker="map" class="mt-3 rounded-lg border border-stone-200 bg-white p-4">
    @csrf

    <fieldset>
        <legend class="sr-only">Choose a stand</legend>

        <div class="grid grid-cols-[1fr_2fr_1fr] grid-rows-[auto_8rem_auto] gap-2">
            <div class="col-start-2 row-start-2 flex items-center justify-center rounded-md border-2 border-white bg-pitch-600 text-xs font-medium uppercase tracking-wider text-pitch-100 outline outline-1 outline-pitch-700">
                Pitch
            </div>

            @foreach ($placed as $allocation)
                @include('fixtures.partials.seat-map-stand', ['position' => $positions[$allocation->stand->name]])
            @endforeach
        </div>

        @if ($unplaced->isNotEmpty())
            <div class="mt-2 grid grid-cols-2 gap-2">
                @foreach ($unplaced as $allocation)
                    @include('fixtures.partials.seat-map-stand', ['position' => ''])
                @endforeach
            </div>
        @endif
    </fieldset>

    <div class="mt-4 flex items-center justify-end gap-2">
        <label for="map-quantity" class="text-sm text-stone-600">Tickets</label>
        <select id="map-quantity" name="quantity" class="rounded-md border border-stone-300 px-2 py-1.5 text-sm">
            @foreach (range(1, \App\Models\Basket::MAX_TICKETS) as $quantity)
                <option value="{{ $quantity }}" @selected(old('quantity') == $quantity)>{{ $quantity }}</option>
            @endforeach
        </select>
        <button type="submit" dusk="select-seats" class="rounded-md bg-pitch-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-pitch-700">Select seats</button>
    </div>
</form>
