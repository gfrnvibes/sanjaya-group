<?php

namespace App\Filament\Resources\ProductVariants\RelationManagers;

use App\Filament\Resources\StockBalances\StockBalanceResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class StockBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'stockBalances';

    protected static ?string $relatedResource = StockBalanceResource::class;

    protected static ?string $title = 'Stok per Gudang';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
