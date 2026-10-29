<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::inertia('/orders', 'Orders/Page');
});
