<?php

namespace App\Filament\Resources\Resellers\RelationManagers;

use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $relatedResource = ResellerInvoiceResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
