<?php

use App\Http\Controllers\OrderController;
use App\Livewire\OrderBoard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::middleware('auth')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/board', OrderBoard::class);
    Route::view('/help', 'help.index');
});
