<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Basket\CurrentBasket;
use App\Enums\ExitReason;
use App\Events\CheckoutExitSurveyAnswered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ExitSurveyController extends Controller
{
    /**
     * Records why a supporter is leaving checkout. One answer per basket;
     * a repeat is accepted but ignored.
     */
    public function store(Request $request, CurrentBasket $currentBasket): Response
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(ExitReason::class)],
        ]);

        $basket = $currentBasket->get();

        if ($basket->exit_reason !== null) {
            return response()->noContent();
        }

        $reason = ExitReason::from($validated['reason']);
        $basket->forceFill(['exit_reason' => $reason])->save();

        CheckoutExitSurveyAnswered::dispatch($basket, $request->user(), $reason);

        return response()->noContent();
    }
}
