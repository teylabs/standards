<?php

namespace FixtureGood\Support\Pages;

use Tey\Kit\Features\Core\ViewModels\ViewModel;

/** Rule ui.page-view-model control: a widget on the plain ViewModel, never rendered as a page. Parsed, never loaded: kept out of the class roots so rule 20 does not reflect a tey/kit base an app without the kit lacks. */
final class OrderTotals extends ViewModel
{
}
