<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get("/projects", fn () => Inertia::render("Projects/Index"));
Route::get("/projects/{project}", fn () => Inertia::render("Projects/Show"));
