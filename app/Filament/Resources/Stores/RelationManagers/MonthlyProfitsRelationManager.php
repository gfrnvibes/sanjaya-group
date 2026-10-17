<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Resources\StoreMonthlyProfits\StoreMonthlyProfitResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class MonthlyProfitsRelationManager extends RelationManager
{
    protected static string $relationship = 'monthlyProfits';

    protected static ?string $relatedResource = StoreMonthlyProfitResource::class;

    protected static ?string $title = 'Laba Rugi Bulanan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
