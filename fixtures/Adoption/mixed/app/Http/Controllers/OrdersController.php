<?php

namespace App\Http\Controllers;

use App\Models\Order;

final class OrdersController
{
    public function index()
    {
        return inertia('Orders/Page', ['orders' => Order::query()->paginate(20)]);
    }
}
