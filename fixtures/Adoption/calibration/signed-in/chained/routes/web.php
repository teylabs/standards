<?php

use Illuminate\Support\Facades\Route;

Route::get('/account', fn () => view('account'))->middleware('auth');
