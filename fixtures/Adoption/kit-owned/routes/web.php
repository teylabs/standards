<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', fn () => inertia('Dashboard/Page'))->name('dashboard');
});
