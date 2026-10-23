<?php

namespace App\Filament\Resources\WorkOrders\Tables;

use App\Enums\WorkOrderStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['tailor', 'cutter', 'warehouse', 'goodsWarehouse'])
                ->withSum('items as qty_cut', 'qty_cut')
                ->withSum('items as qty_good', 'qty_good')
                ->withSum('items as qty_reject', 'qty_reject'))
            ->defaultSort('cut_date', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('No. SPK')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cut_date')
                    ->label('Potong')
                    ->date()
                    ->sortable(),
                TextColumn::make('tailor.name')
                    ->label('Penjahit')
                    ->searchable()
                    ->placeholder('Belum dicatat'),
                TextColumn::make('cutter.name')
                    ->label('Tukang Potong')
                    ->placeholder('Belum dicatat')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (WorkOrderStatus $state): string => match ($state) {
                        WorkOrderStatus::Cutting => 'warning',
                        WorkOrderStatus::Sewing => 'info',
                        WorkOrderStatus::Done => 'success',
                        WorkOrderStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('qty_cut')
                    ->label('Potong')
                    ->numeric(),
                TextColumn::make('qty_good')
                    ->label('Jadi')
                    ->numeric(),
                TextColumn::make('qty_reject')
                    ->label('Reject')
                    ->numeric()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('finished_date')
                    ->label('Selesai')
                    ->date()
                    ->placeholder('-'),
                TextColumn::make('warehouse.name')
                    ->label('Gudang Bahan')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('goodsWarehouse.name')
                    ->label('Gudang Barang Jadi')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(WorkOrderStatus::class)
                    ->multiple(),
                SelectFilter::make('tailor')
                    ->label('Penjahit')
                    ->relationship('tailor', 'name')
                    ->preload(),
                Filter::make('cut_date')
                    ->schema([
                        DatePicker::make('from')->label('Potong dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('cut_date', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('cut_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
