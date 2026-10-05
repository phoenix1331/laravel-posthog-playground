<?php

declare(strict_types=1);

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use RuntimeException;

/**
 * Admin-only route that fails on purpose, to see an error arrive in PostHog
 * error tracking next to the session replay that caused it.
 */
class ErrorDebugController extends Controller
{
    public function throw(): never
    {
        throw new RuntimeException('Matchday test error: thrown on purpose from /debug/error');
    }
}
