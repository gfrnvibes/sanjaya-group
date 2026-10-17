<?php

namespace App\Filament\Resources\StockAdjustments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
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
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
            ]);
    }
}
