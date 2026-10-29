<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('app')->group(function () {
    require __DIR__.'/app.php';
});

Route::middleware('auth')->group(base_path('routes/settings.php'));
