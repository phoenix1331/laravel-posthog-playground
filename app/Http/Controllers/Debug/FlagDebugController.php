<?php

declare(strict_types=1);

namespace App\Http\Controllers\Debug;

use App\Analytics\Flags\CachedFlagDefinitions;
use App\Analytics\Flags\FlagEvaluationBenchmark;
use App\Analytics\Flags\FlagScope;
use App\Http\Controllers\Controller;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * Admin-only page comparing local and remote flag evaluation. Debug
 * controllers are the one place allowed to touch the analytics layer
 * directly (see the arch rules).
 */
class FlagDebugController extends Controller
{
    private const int MAX_SAMPLES = 25;

    public function show(Request $request, FlagEvaluationBenchmark $benchmark): View
    {
        $samples = max(1, min(self::MAX_SAMPLES, $request->integer('samples', 10)));
        $current = FlagScope::current();

        if (! AnalyticsServiceProvider::sending()) {
            return view('debug.flags', [
                'sending' => false,
                'current' => $current,
                'fallbacks' => Feature::for($current)->all(),
                'samples' => $samples,
            ]);
        }

        $scopes = [$current, ...array_map(fn (int $i): FlagScope => new FlagScope("debug-sample-{$i}"), range(1, $samples - 1))];

        return view('debug.flags', [
            'sending' => true,
            'current' => $current,
            'samples' => $samples,
            'configuredMode' => config('posthog.flags.evaluation'),
            'result' => $benchmark->run($scopes),
        ]);
    }

    public function clearDefinitions(): RedirectResponse
    {
        Cache::forget(CachedFlagDefinitions::KEY);

        return redirect()->route('debug.flags')->with('status', 'Flag definitions cache cleared. The next local evaluation downloads them again.');
    }
}
