<?php

namespace App\Filament\Resources\Materials\RelationManagers;

use App\Filament\Resources\MaterialBalances\MaterialBalanceResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class BalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'balances';

    protected static ?string $relatedResource = MaterialBalanceResource::class;

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
