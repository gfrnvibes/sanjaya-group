<?php

namespace App\Filament\Resources\Materials\RelationManagers;

use App\Filament\Resources\MaterialMovements\MaterialMovementResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $relatedResource = MaterialMovementResource::class;

    protected static ?string $title = 'Kartu Stok Bahan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
