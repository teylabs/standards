<?php

use FixtureGood\Domain\Billing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::controller(InvoiceController::class)->group(function () {
    Route::get('/invoices', 'index');
});
