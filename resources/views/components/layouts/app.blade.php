@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? "{$title} | " : '' }}{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
        <header class="bg-pitch-900 text-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ route('fixtures.index') }}" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                    <span class="inline-block size-3 rounded-full bg-floodlight"></span>
                    Matchday
                </a>
                <nav class="text-sm text-pitch-100">
                    <a href="{{ route('fixtures.index') }}" class="hover:text-white">Fixtures</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-5xl px-4 pb-10 text-xs text-stone-500">
            Invented clubs, fake payments. A PostHog and Laravel playground.
        </footer>
    </body>
</html>
