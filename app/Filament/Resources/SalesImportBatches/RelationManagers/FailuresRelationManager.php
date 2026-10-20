<?php

namespace App\Filament\Resources\SalesImportBatches\RelationManagers;

use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class FailuresRelationManager extends RelationManager
{
    protected static string $relationship = 'failures';

    protected static ?string $relatedResource = SalesImportFailureResource::class;

    protected static ?string $title = 'Baris Gagal';

    public function table(Table $table): Table
    {
        return $table;
    }
}
