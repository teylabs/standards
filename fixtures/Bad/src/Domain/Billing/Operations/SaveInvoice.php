<?php

namespace FixtureBad\Domain\Billing\Operations;

use Illuminate\Support\Facades\Mail;

class SaveInvoice
{
    public function handle(): void
    {
        Mail::to('reader@example.test');
    }
}
