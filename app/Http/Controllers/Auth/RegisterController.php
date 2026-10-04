<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Basket\CurrentBasket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\SupportersClub;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    use RedirectsAfterAuthentication;

    public function create(): View
    {
        return view('auth.register', [
            'supportersClubs' => SupportersClub::orderBy('name')->get(),
        ]);
    }

    public function store(RegisterRequest $request, CurrentBasket $basket): RedirectResponse
    {
        $user = User::create($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return $this->redirectAfterAuthentication($basket);
    }
}
