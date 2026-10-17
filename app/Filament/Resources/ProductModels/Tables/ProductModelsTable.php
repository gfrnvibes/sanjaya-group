<?php

namespace App\Filament\Resources\ProductModels\Tables;

use App\Enums\ProductKind;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('category')->withCount(['variants', 'boms']))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('variants_count')
                    ->label('Varian')
                    ->badge(),
                TextColumn::make('boms_count')
                    ->label('Versi Resep')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('default_margin_pct')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                    ->placeholder('Default')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Jenis')
                    ->options(ProductKind::class),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
