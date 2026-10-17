<?php

namespace App\Filament\Resources\MaterialPurchases\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaterialPurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['supplier', 'warehouse'])
                ->withCount('items')
                ->withSum('items as total_price', 'total_price'))
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                TextColumn::make('purchase_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('ref_no')
                    ->label('No. Nota')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('items_count')
                    ->label('Baris'),
                TextColumn::make('total_price')
                    ->label('Total')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->preload(),
                Filter::make('purchase_date')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('purchase_date', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('purchase_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
