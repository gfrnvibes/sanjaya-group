<?php

namespace App\Filament\Resources\MaterialPurchases\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Bahan yang Dibeli';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('material'))
            ->columns([
                TextColumn::make('material.code')
                    ->label('Bahan'),
                TextColumn::make('qty_pack')
                    ->label('Qty Beli')
                    ->formatStateUsing(fn ($state, $record): string => number_format((float) $state, 2, ',', '.').' '.$record->material->pack_uom),
                TextColumn::make('qty_base')
                    ->label('Isi Aktual')
                    ->formatStateUsing(fn ($state, $record): string => number_format((float) $state, 0, ',', '.').' '.$record->material->base_uom->value),
                TextColumn::make('total_price')
                    ->label('Total Harga')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('unit_cost')
                    ->label('Harga / Satuan Dasar')
                    ->money('IDR', decimalPlaces: 2),
            ]);
    }
}
