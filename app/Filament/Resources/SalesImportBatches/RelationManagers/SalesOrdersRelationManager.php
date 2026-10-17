<?php

namespace App\Filament\Resources\SalesImportBatches\RelationManagers;

use App\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SalesOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'salesOrders';

    protected static ?string $relatedResource = SalesOrderResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
