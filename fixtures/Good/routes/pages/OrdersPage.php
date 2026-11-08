<?php

namespace FixtureGood\Support\Pages;

use Tey\Kit\Features\Core\ViewModels\PageViewModel;

/** Rule ui.page-view-model control: a page on PageViewModel. Parsed, never loaded: kept out of the class roots so rule 20 does not reflect a tey/kit base an app without the kit lacks. */
final class OrdersPage extends PageViewModel
{
    public function title(): string
    {
        return 'Orders';
    }
}
