<?php

final class OrdersController
{
    public function index()
    {
        $table = TableQuery::for(Order::query())
            ->rows(fn ($order) => ['id' => $order->id, 'actions' => [new Action('Edit', '/orders/1/edit', 'get', key: 'edit')]]);

        return Inertia::render('Orders', ['table' => $table->toData()]);
    }
}
