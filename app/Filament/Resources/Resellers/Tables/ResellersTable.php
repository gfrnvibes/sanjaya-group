<?php

namespace App\Filament\Resources\Resellers\Tables;

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

class ResellersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['owner', 'receivable'])->withCount('stores'))
            ->columns([
                TextColumn::make('name')
                    ->label('Reseller')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Pemilik')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('stores_count')
                    ->label('Toko')
                    ->badge(),
                TextColumn::make('opening_balance')
                    ->label('Saldo Awal')
                    ->money('IDR', decimalPlaces: 0)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('receivable.outstanding')
                    ->label('Sisa Piutang')
                    ->money('IDR', decimalPlaces: 0)
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'success'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('deleted_at')
                    ->label('Dihapus')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('owner')
                    ->label('Pemilik')
                    ->relationship('owner', 'name')
                    ->searchable()
                    ->preload(),
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
