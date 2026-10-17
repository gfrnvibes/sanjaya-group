<?php

namespace App\Filament\Resources\Channels\RelationManagers;

use App\Filament\Resources\Stores\StoreResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class StoresRelationManager extends RelationManager
{
    protected static string $relationship = 'stores';

    protected static ?string $relatedResource = StoreResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
