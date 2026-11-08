<?php

namespace FixtureGood\Support\Pages;

use Inertia\Inertia;
use Inertia\Response;

/** Rule ui.page-view-model control: renders a PageViewModel as the page. */
final class ShowOrders
{
    public function __invoke(): Response
    {
        return Inertia::render('Orders/Page', new OrdersPage);
    }
}
