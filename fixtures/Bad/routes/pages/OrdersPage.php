<?php

namespace FixtureBad\Support\Pages;

use Tey\Kit\Features\Core\ViewModels\ViewModel;

/** Rule ui.page-view-model violation: a page's props on the plain ViewModel. Parsed, never loaded: kept out of the class roots so rule 20 does not reflect a tey/kit base an app without the kit lacks. */
final class OrdersPage extends ViewModel
{
}
