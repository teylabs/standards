<?php

use App\Modules\Billing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/billing/settings', [InvoiceController::class, 'settings']);
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy']);
});
