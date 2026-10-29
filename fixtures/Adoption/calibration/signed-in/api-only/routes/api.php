<?php

use Illuminate\Support\Facades\Route;

// routes/api.php is the API: never a signed-in page, even when a closure here calls view().
Route::middleware('auth:sanctum')->get('/user', fn () => view('user'));
