<?php

namespace App\Filament\Resources\GoodsReceipts\Tables;

use App\Enums\GoodsReceiptSource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GoodsReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'supplier'])
                ->withSum('items as total_qty', 'qty'))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('receipt_no')
                    ->label('No. Dokumen')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('-'),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('total_qty')
                    ->label('Total Qty')
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(GoodsReceiptSource::class),
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name'),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('received_at', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('received_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
