<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

final class OrdersController
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Orders/Index');
    }
}
