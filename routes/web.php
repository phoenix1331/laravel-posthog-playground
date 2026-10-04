<?php

declare(strict_types=1);

use App\Http\Controllers\FixtureController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FixtureController::class, 'index'])->name('fixtures.index');
Route::get('/fixtures/{fixture}', [FixtureController::class, 'show'])->name('fixtures.show');
