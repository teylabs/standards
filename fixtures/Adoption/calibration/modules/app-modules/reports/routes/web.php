<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/reports/search', fn () => inertia('Reports/Search'))->name('reports.search');
});
