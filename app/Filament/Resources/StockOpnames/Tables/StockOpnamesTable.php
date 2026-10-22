<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Enums\StockOpnameStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('warehouse')
                ->withCount('items')
                ->withSum('items as total_diff', 'diff'))
            ->defaultSort('counted_at', 'desc')
            ->columns([
                TextColumn::make('counted_at')
                    ->label('Waktu Hitung')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (StockOpnameStatus $state): string => $state === StockOpnameStatus::Posted ? 'success' : 'warning'),
                TextColumn::make('items_count')
                    ->label('Varian Dihitung'),
                TextColumn::make('total_diff')
                    ->label('Total Selisih')
                    ->numeric()
                    ->color(fn ($state): string => (int) $state === 0 ? 'gray' : ((int) $state > 0 ? 'success' : 'danger')),
                TextColumn::make('posted_at')
                    ->label('Diposting')
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockOpnameStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ]);
    }
}
