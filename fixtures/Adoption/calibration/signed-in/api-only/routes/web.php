<?php

use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/tokens', fn () => inertia('Tokens'));
});

Route::prefix('api/v2')->middleware('auth')->group(function () {
    Route::get('/orders', fn () => view('orders'));
});

Route::post('/logout', [TokenController::class, 'destroy'])->middleware('auth');

Route::get('/welcome', fn () => inertia('Welcome'));
