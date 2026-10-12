<?php

namespace FixtureBad\UI;

use function route as hostRoute;

class Routes
{
    public function url(): string
    {
        return hostRoute('invoices');
    }
}
