<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    require __DIR__.'/'.config('app.area').'.php';
});
