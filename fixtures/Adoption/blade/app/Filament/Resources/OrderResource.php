<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    public static function table(Table $table): Table
    {
        return $table->columns([]);
    }
}
