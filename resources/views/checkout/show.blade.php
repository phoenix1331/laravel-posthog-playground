<x-layouts.app title="Checkout">
    @php($money = fn (int $pence) => \App\Support\Money::format($pence))

    <h1 class="text-2xl font-semibold tracking-tight">Checkout</h1>

    @error('basket')
        <p class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <div class="mt-6 grid gap-6 md:grid-cols-2">
        <section class="rounded-lg border border-stone-200 bg-white p-6">
            <h2 class="font-semibold">{{ $basket->fixture->title() }}</h2>
            <p class="text-sm text-stone-600">{{ $basket->fixture->kicks_off_at->format('l j F, H:i') }}</p>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt>{{ $basket->quantity }} &times; {{ $basket->ticketAllocation->stand->name }}</dt>
                    <dd>{{ $money($basket->ticketTotalPence()) }}</dd>
                </div>
                @if ($basket->travelOption)
                    <div class="flex justify-between">
                        <dt>{{ $basket->quantity }} &times; coach from {{ $basket->travelOption->departure_point }}</dt>
                        <dd>{{ $money($basket->travelTotalPence()) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-stone-200 pt-2 font-semibold">
                    <dt>Total</dt>
                    <dd>{{ $money($basket->totalPence()) }}</dd>
                </div>
            </dl>

            <a href="{{ route('basket.show') }}" class="mt-4 inline-block text-sm text-pitch-700 hover:underline">Edit basket</a>
        </section>

        <form method="POST" action="{{ route('checkout.store') }}" class="space-y-4 rounded-lg border border-stone-200 bg-white p-6">
            @csrf

            <div>
                <h2 class="font-semibold">Payment</h2>
                <p class="text-xs text-stone-500">
                    Fake payment, nothing is charged or stored. Use {{ chunk_split($testCard, 4, ' ') }}.
                </p>
            </div>

            {{-- ph-no-capture: session replay never records these fields at all --}}
            <x-form.field name="name_on_card" label="Name on card" required autocomplete="cc-name" class="ph-no-capture" :value="old('name_on_card', auth()->user()->name)" />
            <x-form.field name="card_number" label="Card number" type="password" required inputmode="numeric" autocomplete="off" class="ph-no-capture" placeholder="4242 4242 4242 4242" />

            {{-- Label chosen server-side by the checkout-cta experiment, so it never flickers --}}
            <button type="submit" dusk="pay" class="w-full rounded-md bg-pitch-600 px-4 py-2 text-sm font-medium text-white hover:bg-pitch-700">
                {{ $ctaLabel }}
            </button>
        </form>
    </div>
</x-layouts.app>
