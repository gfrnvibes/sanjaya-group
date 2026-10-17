<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\StockMovements\Pages\ManageStockMovements;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Mutasi Stok';

    protected static ?string $pluralModelLabel = 'Kartu Stok Barang';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant', 'warehouse', 'creator']))
            ->defaultSort('moved_at', 'desc')
            ->columns([
                TextColumn::make('moved_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang')
                    ->toggleable(),
                TextColumn::make('movement_type')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state),
                TextColumn::make('unit_cost')
                    ->label('Biaya / pcs')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('source_type')
                    ->label('Sumber')
                    ->formatStateUsing(fn (?string $state, StockMovement $record): string => $state ? str($state)->headline().' #'.$record->source_id : '-')
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label('Jenis')
                    ->options(StockMovementType::class)
                    ->multiple(),
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name')
                    ->preload(),
                SelectFilter::make('variant')
                    ->label('Varian')
                    ->relationship('variant', 'sku')
                    ->searchable(),
                Filter::make('moved_at')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('moved_at', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('moved_at', '<=', $date))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStockMovements::route('/'),
        ];
    }
}
