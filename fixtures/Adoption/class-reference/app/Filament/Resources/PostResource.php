<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\TextInput;

final class PostResource
{
    public static function fields(): array
    {
        return [TextInput::make('title')];
    }
}
