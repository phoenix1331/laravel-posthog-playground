<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Basket\CurrentBasket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use RedirectsAfterAuthentication;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, CurrentBasket $basket): RedirectResponse
    {
        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Regenerating keeps session data, so the basket survives login
        $request->session()->regenerate();

        return $this->redirectAfterAuthentication($basket);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('fixtures.index');
    }
}
