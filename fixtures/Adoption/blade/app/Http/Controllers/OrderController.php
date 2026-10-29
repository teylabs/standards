<?php

namespace App\Http\Controllers;

class OrderController
{
    public function index()
    {
        return view('orders.index', ['orders' => []]);
    }

    public function export()
    {
        return view('orders.export');
    }
}
