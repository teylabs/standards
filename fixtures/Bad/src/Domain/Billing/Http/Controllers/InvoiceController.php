<?php

namespace FixtureBad\Domain\Billing\Http\Controllers;

class InvoiceController
{
    public function __invoke(): void {}

    public function store(): void {}

    public function pay(): void {}

    public function middleware(): array
    {
        return [];
    }
}
