{{--
    Exit survey: opened by resources/js/exit-survey.js when the pointer heads
    out of the window on the checkout page, once per basket. Answers post to
    the server, which records them on the basket and in analytics.
--}}
<dialog id="exit-survey" dusk="exit-survey"
        data-basket="{{ $basket->id }}"
        data-url="{{ route('checkout.exit-survey') }}"
        data-survey-id="{{ config('posthog.surveys.checkout_exit') }}"
        class="w-full max-w-md rounded-lg p-0 shadow-xl backdrop:bg-stone-900/40">
    <form method="dialog" data-exit-survey-form class="p-6">
        <div class="flex items-start justify-between gap-4">
            <h2 class="text-lg font-semibold">Before you go, what stopped you?</h2>
            <button type="submit" value="dismiss" data-exit-survey-dismiss aria-label="Close" class="text-stone-400 hover:text-stone-600">&times;</button>
        </div>
        <p class="mt-1 text-sm text-stone-600">One click helps us fix it. Your basket is saved.</p>

        <fieldset class="mt-4 space-y-2">
            <legend class="sr-only">Reason</legend>
            @foreach (\App\Enums\ExitReason::cases() as $reason)
                <label class="flex items-center gap-2 rounded-md border border-stone-200 px-3 py-2 text-sm hover:border-pitch-600">
                    <input type="radio" name="reason" value="{{ $reason->value }}" required class="text-pitch-600">
                    {{ $reason->label() }}
                </label>
            @endforeach
        </fieldset>

        <button type="submit" value="answer" dusk="exit-survey-submit" class="mt-4 w-full rounded-md bg-pitch-600 px-4 py-2 text-sm font-medium text-white hover:bg-pitch-700">Send</button>
        <p data-exit-survey-thanks hidden class="mt-3 text-center text-sm text-pitch-700">Thanks, that really helps.</p>
    </form>
</dialog>
