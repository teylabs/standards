<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/pairing', fn () => inertia('Pairing/Page'))->name('pairing');
});
