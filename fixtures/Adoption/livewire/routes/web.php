<?php

use App\Livewire\Account\Settings;
use App\Livewire\Billing\InvoiceList;
use App\Livewire\Dashboard;
use App\Livewire\Live\Ticker;
use App\Livewire\Pricing;
use App\Livewire\Reports\Index;
use App\Livewire\Teams\Roster;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/pricing', Pricing::class);
Volt::route('/about', 'about');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/billing/invoices', InvoiceList::class);
    Route::get('/reports', Index::class);
    Route::get('/teams', Roster::class);
    Route::get('/ticker', Ticker::class);
    Route::get('/settings', Settings::class);
    Volt::route('/profile', 'profile.edit');
});
