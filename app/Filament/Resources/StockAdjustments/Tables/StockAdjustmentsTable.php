<?php

namespace App\Filament\Resources\StockAdjustments\Tables;

use App\Enums\StockAdjustmentReason;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'creator'])
                ->withCount('items')
                ->withSum('items as net_qty', 'qty'))
            ->defaultSort('adjusted_at', 'desc')
            ->columns([
                TextColumn::make('adjusted_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('items_count')
                    ->label('Baris'),
                TextColumn::make('net_qty')
                    ->label('Net Qty')
                    ->numeric()
                    ->color(fn ($state): string => (int) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40),
                TextColumn::make('creator.name')
                    ->label('Oleh')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->label('Alasan')
                    ->options(StockAdjustmentReason::class),
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
