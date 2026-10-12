<?php

namespace FixtureBad\Domain\Billing;

class Tokens
{
    public function token(string $value): string
    {
        return md5($value);
    }
}
