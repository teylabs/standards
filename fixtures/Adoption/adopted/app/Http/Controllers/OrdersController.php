<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Acme\Reports\Exporter;
use Acme\Reports\PdfExporter;

final class OrdersController
{
    public function index()
    {
        return inertia('Orders/Page', ['orders' => Order::query()->paginate(20)]);
    }
}
