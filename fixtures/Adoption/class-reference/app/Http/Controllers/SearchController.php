<?php

namespace App\Http\Controllers;

final class SearchController
{
    public function fields(): array
    {
        return [\Filament\Forms\Components\TextInput::class, \Filament\Forms\Components\Select::class];
    }
}
