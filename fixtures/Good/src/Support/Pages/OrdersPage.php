<?php

namespace FixtureGood\Support\Pages;

use Tey\Kit\Features\Core\ViewModels\PageViewModel;

/** Rule ui.page-view-model control: a page on PageViewModel. */
final class OrdersPage extends PageViewModel
{
    public function title(): string
    {
        return 'Orders';
    }
}
