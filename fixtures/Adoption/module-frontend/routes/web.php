<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/invoices', fn () => inertia('Billing::Invoices/Page'))->name('invoices');
    Route::get('/search', fn () => inertia('Billing::Invoices/Page'))->name('search');
});
