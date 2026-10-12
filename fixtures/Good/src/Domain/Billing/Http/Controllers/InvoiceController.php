<?php

namespace FixtureGood\Domain\Billing\Http\Controllers;

class InvoiceController
{
    public function index(): void {}

    public static function middleware(): array
    {
        return [];
    }
}
