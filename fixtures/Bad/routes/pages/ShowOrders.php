<?php

namespace FixtureBad\Support\Pages;

use Inertia\Inertia;
use Inertia\Response;

/** Rule ui.page-view-model violation: renders a plain ViewModel as the page. Parsed, never loaded: kept out of the class roots so rule 20 does not reflect a tey/kit base an app without the kit lacks. */
final class ShowOrders
{
    public function __invoke(): Response
    {
        return Inertia::render('Orders/Page', new OrdersPage);
    }
}
