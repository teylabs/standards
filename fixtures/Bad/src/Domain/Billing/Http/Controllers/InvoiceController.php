<?php

namespace FixtureBad\Domain\Billing\Http\Controllers;

class InvoiceController
{
    public function store(): mixed
    {
        validator([]);

        return back()->with('status', 'Saved');
    }

    public function __invoke(): void {}

    public function pay(): void {}

    public function middleware(): array
    {
        return [];
    }
}
