<?php

use FixtureBad\Domain\Billing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::post('/invoices/pay', [InvoiceController::class, 'pay']);
Route::get('/old', 'InvoiceController@old');
Route::resource('invoices', InvoiceController::class);
Route::controller(InvoiceController::class)->group(function () {
    Route::post('/invoices/reorder', 'reorder');
});
