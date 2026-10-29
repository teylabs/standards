<?php

use App\Http\Controllers\OrdersController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth']], function () {
    Route::get('/orders', [OrdersController::class, 'index']);
});
