<?php

namespace App\Filament\Resources\MaterialBalances;

use App\Filament\Resources\MaterialBalances\Pages\ManageMaterialBalances;
use App\Filament\Resources\Materials\MaterialResource;
use App\Models\MaterialBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MaterialBalanceResource extends Resource
{
    protected static ?string $model = MaterialBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Saldo Bahan';

    protected static ?string $pluralModelLabel = 'Saldo Bahan per Gudang';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['material', 'warehouse']))
            ->defaultSort('qty')
            ->columns([
                TextColumn::make('material.code')
                    ->label('Bahan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('qty')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, MaterialBalance $record): string => number_format((float) $state, 0, ',', '.').' '.$record->material->base_uom->value)
                    ->badge()
                    ->color(fn (MaterialBalance $record): string => (float) $record->qty <= (float) $record->material->min_stock ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('pack')
                    ->label('Setara')
                    ->state(fn (MaterialBalance $record): string => number_format((float) $record->qty / (float) $record->material->qty_per_pack, 2, ',', '.').' '.$record->material->pack_uom),
                TextColumn::make('updated_at')
                    ->label('Terakhir Bergerak')
                    ->since(),
            ])
            ->filters([
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name')
                    ->preload(),
                Filter::make('below_min')
                    ->label('Di bawah minimum')
                    ->query(fn (Builder $query): Builder => $query->whereHas('material', fn (Builder $material) => $material->whereColumn('materials.min_stock', '>=', 'material_balances.qty'))),
            ])
            ->recordActions([
                Action::make('openMaterial')
                    ->label('Kartu Stok')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (MaterialBalance $record): string => MaterialResource::getUrl('view', ['record' => $record->material_id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMaterialBalances::route('/'),
        ];
    }
}
