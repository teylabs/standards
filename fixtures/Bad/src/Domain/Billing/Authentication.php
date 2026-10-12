<?php

namespace FixtureBad\Domain\Billing;

use Laravel\Fortify\Fortify as AuthBackend;

class Authentication
{
    public function register(): void
    {
        AuthBackend::authenticateUsing(fn () => true);
    }
}
