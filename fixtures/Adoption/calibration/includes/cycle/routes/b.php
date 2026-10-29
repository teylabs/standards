<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/a.php';

Route::get('/b', fn () => inertia('B'));
