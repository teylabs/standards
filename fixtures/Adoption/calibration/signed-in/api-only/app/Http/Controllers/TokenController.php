<?php

namespace App\Http\Controllers;

final class TokenController
{
    public function destroy(): array
    {
        return ['ok' => true];
    }
}
