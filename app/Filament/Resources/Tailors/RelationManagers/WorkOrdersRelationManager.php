<?php

namespace App\Filament\Resources\Tailors\RelationManagers;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class WorkOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static ?string $relatedResource = WorkOrderResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
