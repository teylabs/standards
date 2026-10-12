<?php

namespace FixtureBad\Domain\Billing;

class Debugging
{
    public function inspect(): void
    {
        dd('invoice');
        var_dump('invoice');
        exit;
    }
}
