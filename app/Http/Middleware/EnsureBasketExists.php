<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Basket\CurrentBasket;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBasketExists
{
    public function __construct(private readonly CurrentBasket $basket) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->basket->get() === null) {
            return redirect()->route('fixtures.index')->with('status', 'Your basket is empty. Pick a fixture to get started.');
        }

        return $next($request);
    }
}
