<?php

namespace App\Filament\Resources\MaterialMovements;

use App\Enums\MaterialMovementType;
use App\Filament\Resources\MaterialMovements\Pages\ManageMaterialMovements;
use App\Models\MaterialMovement;
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

class MaterialMovementResource extends Resource
{
    protected static ?string $model = MaterialMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Mutasi Bahan';

    protected static ?string $pluralModelLabel = 'Kartu Stok Bahan';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['material', 'warehouse', 'creator']))
            ->defaultSort('moved_at', 'desc')
            ->columns([
                TextColumn::make('moved_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('material.code')
                    ->label('Bahan')
                    ->searchable(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang')
                    ->toggleable(),
                TextColumn::make('movement_type')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state, MaterialMovement $record): string => ((float) $state > 0 ? '+' : '').number_format((float) $state, 2, ',', '.').' '.$record->material->base_uom->value)
                    ->color(fn ($state): string => (float) $state > 0 ? 'success' : 'danger'),
                TextColumn::make('unit_cost')
                    ->label('Harga / Satuan')
                    ->money('IDR', decimalPlaces: 2)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40),
                TextColumn::make('creator.name')
                    ->label('Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label('Jenis')
                    ->options(MaterialMovementType::class)
                    ->multiple(),
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name')
                    ->preload(),
                SelectFilter::make('material')
                    ->label('Bahan')
                    ->relationship('material', 'code')
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
            'index' => ManageMaterialMovements::route('/'),
        ];
    }
}
