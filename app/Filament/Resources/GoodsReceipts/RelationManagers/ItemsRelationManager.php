<?php

namespace App\Filament\Resources\GoodsReceipts\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Barang';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('variant'))
            ->columns([
                TextColumn::make('variant.sku')
                    ->label('SKU'),
                TextColumn::make('variant.name')
                    ->label('Nama'),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('unit_cost')
                    ->label('HPP / pcs')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-'),
            ]);
    }
}
