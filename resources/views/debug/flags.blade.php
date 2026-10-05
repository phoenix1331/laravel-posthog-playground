<x-layouts.app title="Flag evaluation">
    <h1 class="text-2xl font-semibold tracking-tight">Flag evaluation: local vs remote</h1>
    <p class="mt-1 text-sm text-stone-600">
        You are <code class="rounded bg-stone-100 px-1">{{ $current->distinctId }}</code>.
        Local evaluation computes flags in PHP from cached definitions; remote asks PostHog's <code>/flags</code> for every new visitor.
    </p>

    @unless ($sending)
        <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">Not sending to PostHog</p>
            <p class="mt-1">Set <code>POSTHOG_PROJECT_TOKEN</code> (and <code>POSTHOG_PERSONAL_API_KEY</code> for local evaluation) to compare modes. Until then flags come from the app's fallbacks:</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 font-mono text-xs">
                @forelse ($fallbacks as $flag => $value)
                    <dt>{{ $flag }}</dt>
                    <dd>{{ json_encode($value) }}</dd>
                @empty
                    <dt class="col-span-2">No features defined.</dt>
                @endforelse
            </dl>
        </section>
    @else
        @php
            $local = $result['local'];
            $remote = $result['remote'];
        @endphp

        <section class="mt-6 grid gap-4 md:grid-cols-2">
            @foreach (['Local' => $local, 'Remote' => $remote] as $label => $mode)
                <div class="rounded-lg border border-stone-200 bg-white p-4" data-mode="{{ strtolower($label) }}">
                    <div class="flex items-baseline justify-between">
                        <h2 class="font-semibold">{{ $label }}</h2>
                        @if ($configuredMode === strtolower($label))
                            <span class="rounded-full bg-pitch-50 px-2 py-0.5 text-xs text-pitch-700">configured</span>
                        @endif
                    </div>

                    @if ($mode === null)
                        <p class="mt-2 text-sm text-stone-600">Unavailable: set <code>POSTHOG_PERSONAL_API_KEY</code> to download flag definitions.</p>
                    @else
                        @if ($mode['error'])
                            <p class="mt-2 text-sm text-red-700">{{ $mode['error'] }}</p>
                        @endif
                        <dl class="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                            <dt class="text-stone-500">Client setup</dt><dd class="text-right font-mono">{{ $mode['setup_ms'] }} ms</dd>
                            <dt class="text-stone-500">Per visitor (min / mean / max)</dt><dd class="text-right font-mono">{{ $mode['min_ms'] }} / {{ $mode['mean_ms'] }} / {{ $mode['max_ms'] }} ms</dd>
                            <dt class="text-stone-500">Total for {{ $samples }} visitors</dt><dd class="text-right font-mono font-semibold">{{ $mode['total_ms'] }} ms</dd>
                        </dl>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="mt-4 flex items-center justify-between rounded-lg border border-stone-200 bg-white p-4 text-sm">
            <p>
                Definitions cache:
                @if ($result['definitions']['cached'])
                    <span class="font-medium text-pitch-700">warm</span> ({{ $result['definitions']['flags'] }} flags)
                @else
                    <span class="font-medium text-amber-700">cold</span> for this run, so local setup included downloading definitions
                @endif
            </p>
            <form method="POST" action="{{ route('debug.flags.clear') }}">
                @csrf
                <button type="submit" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs hover:bg-stone-50">Clear definitions cache</button>
            </form>
        </section>

        <section class="mt-6">
            <h2 class="text-lg font-semibold">Values per visitor</h2>
            <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200 bg-white">
                <table class="min-w-full text-left text-xs">
                    <thead class="bg-stone-50 text-stone-500">
                        <tr>
                            <th class="px-3 py-2">Distinct ID</th>
                            <th class="px-3 py-2">Local</th>
                            <th class="px-3 py-2">Remote</th>
                            <th class="px-3 py-2">Remote ms</th>
                            <th class="px-3 py-2">Match</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 font-mono">
                        @foreach ($remote['values'] as $distinctId => $values)
                            <tr>
                                <td class="px-3 py-2">{{ $distinctId }}</td>
                                <td class="px-3 py-2">{{ $local === null ? 'n/a' : json_encode($local['values'][$distinctId] ?? []) }}</td>
                                <td class="px-3 py-2">{{ json_encode($values) }}</td>
                                <td class="px-3 py-2">{{ collect($remote['timings'])->firstWhere('distinct_id', $distinctId)['milliseconds'] ?? '' }}</td>
                                <td class="px-3 py-2">
                                    @if ($local === null)
                                        n/a
                                    @elseif ($result['agreement'][$distinctId] ?? false)
                                        <span class="text-pitch-700">yes</span>
                                    @else
                                        <span class="text-red-700">no</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-stone-500">
                A mismatch usually means a flag local evaluation cannot decide (for example cohort or experience-continuity conditions), so the SDK would fall back to a remote call for it.
                Change the sample size with <code>?samples=</code> (1 to 25).
            </p>
        </section>
    @endunless
</x-layouts.app>
