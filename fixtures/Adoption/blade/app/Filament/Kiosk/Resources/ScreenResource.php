<?php

namespace App\Filament\Kiosk\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;

class ScreenResource extends Resource
{
    public static function table(Table $table): Table
    {
        return $table->columns([]);
    }
}
