<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Varian Dipotong';

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
                    ->label('Nama')
                    ->wrap(),
                TextColumn::make('qty_cut')
                    ->label('Potong')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('qty_good')
                    ->label('Jadi')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('qty_reject')
                    ->label('Reject')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('material_cost_snapshot')
                    ->label('Bahan / pcs')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('labor_cost_snapshot')
                    ->label('Ongkos / pcs')
                    ->tooltip('Semua ongkos resep (masuk HPP)')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('sewing_cost_snapshot')
                    ->label('Upah jahit / pcs')
                    ->tooltip('Hanya ongkos JAHIT: dasar upah penjahit')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('cutting_cost_snapshot')
                    ->label('Upah potong / pcs')
                    ->tooltip('Hanya ongkos POTONG: dasar upah tukang potong (x qty potong)')
                    ->money('IDR', decimalPlaces: 0),
            ]);
    }
}
