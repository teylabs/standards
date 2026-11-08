<?php

namespace FixtureBad\Support\Pages;

use Inertia\Inertia;
use Inertia\Response;

/** Rule ui.page-view-model violation: renders a plain ViewModel as the page. */
final class ShowOrders
{
    public function __invoke(): Response
    {
        return Inertia::render('Orders/Page', new OrdersPage);
    }
}
