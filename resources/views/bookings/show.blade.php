<x-layouts.app title="Booking confirmed">
    @php($money = fn (int $pence) => \App\Support\Money::format($pence))

    <div class="mx-auto max-w-xl">
        <p class="text-sm font-medium text-pitch-700">Booking confirmed</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">You're going to {{ $booking->fixture->title() }}</h1>
        <p class="mt-1 text-sm text-stone-600">Reference <span class="font-mono font-medium text-stone-900" data-ph-mask>{{ $booking->reference }}</span></p>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-6">
            <p class="text-sm text-stone-600">
                {{ $booking->fixture->competition->name }} &middot;
                {{ $booking->fixture->kicks_off_at->format('l j F, H:i') }} &middot;
                {{ $booking->fixture->homeClub->stadium }}
            </p>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt>{{ $booking->quantity }} &times; {{ $booking->ticketAllocation->stand->name }}</dt>
                    <dd>{{ $money($booking->ticket_total_pence) }}</dd>
                </div>
                @if ($booking->travelOption)
                    <div class="flex justify-between">
                        <dt>
                            {{ $booking->quantity }} &times; coach from {{ $booking->travelOption->departure_point }},
                            departs {{ $booking->travelOption->departs_at->format('H:i') }}
                        </dt>
                        <dd>{{ $money($booking->travel_total_pence) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-stone-200 pt-2 font-semibold">
                    <dt>Paid</dt>
                    <dd>{{ $money($booking->total_pence) }}</dd>
                </div>
            </dl>
        </section>

        <a href="{{ route('fixtures.index') }}" class="mt-6 inline-block text-sm text-pitch-700 hover:underline">Back to fixtures</a>
    </div>
</x-layouts.app>
