<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $relatedResource = StockMovementResource::class;

    protected static ?string $title = 'Kartu Stok';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
