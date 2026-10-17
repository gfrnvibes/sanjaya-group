<?php

namespace App\Filament\Resources\ResellerInvoices\RelationManagers;

use App\Filament\Resources\SalesReturns\SalesReturnResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $relatedResource = SalesReturnResource::class;

    protected static ?string $title = 'Kredit Retur';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
