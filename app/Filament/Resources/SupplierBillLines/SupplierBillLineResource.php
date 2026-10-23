<?php

namespace App\Filament\Resources\SupplierBillLines;

use App\Filament\Resources\SupplierBillLines\Pages\ListSupplierBillLines;
use App\Models\SupplierBillLine;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * RINCIAN TAGIHAN SUPPLIER: tiap baris = qty pesanan yang ditagihkan ke 1 supplier (dari 1 lot),
 * pengurang retur (qty negatif), titipan grosir, atau qty belum teralokasi. Hanya baca.
 */
class SupplierBillLineResource extends Resource
{
    protected static ?string $model = SupplierBillLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Rincian Tagihan Supplier';

    protected static ?string $pluralModelLabel = 'Rincian Tagihan Supplier';

    protected static ?string $slug = 'tagihan-supplier/rincian';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'variant', 'lot.receiptItem.receipt', 'salesOrderItem.salesOrder.store', 'salesReturn']))
            ->defaultSort('billed_on', 'desc')
            ->columns([
                TextColumn::make('billed_on')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder(fn (SupplierBillLine $record): string => $record->stock_lot_id === null ? 'Belum teralokasi' : 'Tanpa supplier (tunai)')
                    ->searchable(),
                TextColumn::make('salesOrderItem.salesOrder.order_no')
                    ->label('No. pesanan')
                    ->description(fn (SupplierBillLine $record): ?string => $record->salesOrderItem?->salesOrder?->store?->name)
                    ->searchable(),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->description(fn (SupplierBillLine $record): ?string => $record->variant?->name)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->state(fn (SupplierBillLine $record): string => match (true) {
                        $record->sales_return_id !== null => 'Retur',
                        $record->supplier_id === null && $record->stock_lot_id === null => 'Belum teralokasi',
                        $record->supplier_id === null => 'Lot tunai',
                        $record->is_consignment => 'Titipan grosir',
                        default => 'Barang',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Retur' => 'warning',
                        'Belum teralokasi' => 'danger',
                        'Titipan grosir' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('lot')
                    ->label('Lot / Barang Masuk')
                    ->state(fn (SupplierBillLine $record): ?string => $record->lot?->receiptItem?->receipt?->receipt_no)
                    ->description(fn (SupplierBillLine $record): ?string => $record->lot?->received_at?->format('d/m/Y'))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('unit_cost')
                    ->label('Harga / pcs')
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('billed_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('billed_on', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Periode: '.($data['from'] ?? '…').' s/d '.($data['until'] ?? '…')
                        : null),
                TernaryFilter::make('is_consignment')
                    ->label('Titipan grosir')
                    ->trueLabel('Hanya titipan grosir')
                    ->falseLabel('Tanpa titipan grosir'),
                TernaryFilter::make('allocated')
                    ->label('Alokasi')
                    ->trueLabel('Sudah teralokasi')
                    ->falseLabel('Belum teralokasi')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNotNull('stock_lot_id')->orWhereNotNull('supplier_id')),
                        false: fn (Builder $query) => $query->whereNull('supplier_id')->whereNull('stock_lot_id'),
                    ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierBillLines::route('/'),
        ];
    }
}
