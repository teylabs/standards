<?php

use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    require __DIR__.'/app.php';
});
