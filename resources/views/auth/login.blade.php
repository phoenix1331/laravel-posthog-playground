<x-layouts.app title="Log in">
    <div class="mx-auto max-w-sm">
        <h1 class="text-2xl font-semibold tracking-tight">Log in</h1>
        <p class="mt-1 text-sm text-stone-600">
            New here? <a href="{{ route('register') }}" class="text-pitch-700 hover:underline">Create an account</a>.
        </p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4 rounded-lg border border-stone-200 bg-white p-6">
            @csrf

            <x-form.field name="email" label="Email" type="email" required autofocus autocomplete="username" />
            <x-form.field name="password" label="Password" type="password" required autocomplete="current-password" />

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1" class="rounded border-stone-300">
                Remember me
            </label>

            <button type="submit" dusk="log-in" class="w-full rounded-md bg-pitch-600 px-4 py-2 text-sm font-medium text-white hover:bg-pitch-700">Log in</button>
        </form>
    </div>
</x-layouts.app>
