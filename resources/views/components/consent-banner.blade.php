<div id="consent-banner" role="dialog" aria-labelledby="consent-title" @if ($chosen()) hidden @endif
     class="fixed inset-x-0 bottom-0 z-50 border-t border-stone-200 bg-white p-4 shadow-lg">
    <div class="mx-auto flex max-w-5xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-stone-700">
            <span id="consent-title" class="font-medium">Help us improve Matchday?</span>
            We use PostHog for product analytics, including session replays with every form field masked. Nothing is collected in your browser unless you agree.
        </p>
        <div class="flex shrink-0 gap-2">
            <button type="button" data-consent="denied" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm hover:bg-stone-50">Decline</button>
            <button type="button" dusk="consent-accept" data-consent="granted" class="rounded-md bg-pitch-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-pitch-700">Accept</button>
        </div>
    </div>
</div>
