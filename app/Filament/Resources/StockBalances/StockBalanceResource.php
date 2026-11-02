<?php

namespace App\Filament\Resources\StockBalances;

use App\Enums\ProductKind;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\StockBalances\Pages\ManageStockBalances;
use App\Models\StockBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Saldo Stok';

    protected static ?string $pluralModelLabel = 'Stok Barang Jadi';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.model', 'warehouse']))
            ->defaultSort('qty')
            ->columns([
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.name')
                    ->label('Nama')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('variant.model.kind')
                    ->label('Sumber')
                    ->badge()
                    ->color(fn (?ProductKind $state): string => $state === ProductKind::BeliJadi ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang'),
                TextColumn::make('qty')
                    ->label('Stok')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (StockBalance $record): string => $record->qty <= $record->variant->min_stock ? 'danger' : 'success')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('variant.min_stock')
                    ->label('Minimum'),
                TextColumn::make('updated_at')
                    ->label('Terakhir Bergerak')
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->relationship('warehouse', 'name')
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Sumber')
                    ->options([ProductKind::Produksi->value => 'Produksi', ProductKind::BeliJadi->value => 'Beli Jadi'])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'])
                        ? $query
                        : $query->whereHas('variant.model', fn (Builder $model) => $model->where('kind', $data['value']))),
                Filter::make('below_min')
                    ->label('Di bawah minimum')
                    ->query(fn (Builder $query): Builder => $query->whereHas('variant', fn (Builder $variant) => $variant->whereColumn('product_variants.min_stock', '>=', 'stock_balances.qty'))),
                Filter::make('negative')
                    ->label('Stok minus')
                    ->query(fn (Builder $query): Builder => $query->where('qty', '<', 0)),
            ])
            ->recordActions([
                Action::make('openVariant')
                    ->label('Kartu Stok')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (StockBalance $record): string => ProductVariantResource::getUrl('view', ['record' => $record->variant_id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStockBalances::route('/'),
        ];
    }
}
