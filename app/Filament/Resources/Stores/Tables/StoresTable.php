<?php

namespace App\Filament\Resources\Stores\Tables;

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

class StoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['reseller.owner', 'channel'])->withCount('salesOrders'))
            ->columns([
                TextColumn::make('name')
                    ->label('Toko')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reseller.name')
                    ->label('Reseller')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reseller.owner.name')
                    ->label('Pemilik')
                    ->toggleable(),
                TextColumn::make('channel.name')
                    ->label('Channel')
                    ->badge(),
                TextColumn::make('sales_orders_count')
                    ->label('Pesanan')
                    ->sortable(),
                TextColumn::make('external_ref')
                    ->label('ID Eksternal')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('channel')
                    ->label('Channel')
                    ->relationship('channel', 'name')
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
