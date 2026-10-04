<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BasketController;
use App\Http\Controllers\FixtureController;
use App\Http\Controllers\SeatSelectionController;
use App\Http\Controllers\TravelController;
use App\Http\Middleware\EnsureBasketExists;
use Illuminate\Support\Facades\Route;

Route::get('/', [FixtureController::class, 'index'])->name('fixtures.index');
Route::get('/fixtures/{fixture}', [FixtureController::class, 'show'])->name('fixtures.show');
Route::post('/fixtures/{fixture}/seats', [SeatSelectionController::class, 'store'])->name('seats.store');

Route::middleware(EnsureBasketExists::class)->group(function (): void {
    Route::get('/basket', [BasketController::class, 'show'])->name('basket.show');
    Route::post('/basket/travel', [TravelController::class, 'store'])->name('travel.store');
    Route::delete('/basket/travel', [TravelController::class, 'destroy'])->name('travel.destroy');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
