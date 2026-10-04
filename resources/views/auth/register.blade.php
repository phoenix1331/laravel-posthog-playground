<x-layouts.app title="Create an account">
    <div class="mx-auto max-w-sm">
        <h1 class="text-2xl font-semibold tracking-tight">Create an account</h1>
        <p class="mt-1 text-sm text-stone-600">
            Already registered? <a href="{{ route('login') }}" class="text-pitch-700 hover:underline">Log in</a>.
        </p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4 rounded-lg border border-stone-200 bg-white p-6">
            @csrf

            <x-form.field name="name" label="Name" required autofocus autocomplete="name" />
            <x-form.field name="email" label="Email" type="email" required autocomplete="username" />

            <div>
                <label for="supporters_club_id" class="block text-sm font-medium">Supporters' club <span class="font-normal text-stone-500">(optional)</span></label>
                <select id="supporters_club_id" name="supporters_club_id" class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm">
                    <option value="">None</option>
                    @foreach ($supportersClubs as $supportersClub)
                        <option value="{{ $supportersClub->id }}" @selected(old('supporters_club_id') == $supportersClub->id)>{{ $supportersClub->name }}</option>
                    @endforeach
                </select>
                @error('supporters_club_id')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <x-form.field name="password" label="Password" type="password" required autocomplete="new-password" />
            <x-form.field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

            <button type="submit" class="w-full rounded-md bg-pitch-600 px-4 py-2 text-sm font-medium text-white hover:bg-pitch-700">Create account</button>
        </form>
    </div>
</x-layouts.app>
