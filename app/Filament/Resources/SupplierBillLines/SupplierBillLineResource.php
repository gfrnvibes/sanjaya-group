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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'variant', 'goodsReceiptItem.receipt', 'salesOrderItem.salesOrder.store', 'salesReturn']))
            ->defaultSort('billed_on', 'desc')
            ->columns([
                TextColumn::make('billed_on')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('Belum ada supplier (isi di Model Produk)')
                    ->searchable(),
                TextColumn::make('document')
                    ->label('Dokumen')
                    ->state(fn (SupplierBillLine $record): ?string => $record->kind === SupplierBillLine::KIND_PURCHASE
                        ? $record->goodsReceiptItem?->receipt?->receipt_no
                        : $record->salesOrderItem?->salesOrder?->order_no)
                    ->description(fn (SupplierBillLine $record): ?string => $record->kind === SupplierBillLine::KIND_PURCHASE
                        ? 'Barang Masuk'
                        : $record->salesOrderItem?->salesOrder?->store?->name)
                    ->placeholder('-'),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->description(fn (SupplierBillLine $record): ?string => $record->variant?->name)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->state(fn (SupplierBillLine $record): string => match (true) {
                        $record->kind === SupplierBillLine::KIND_PURCHASE => 'Pembelian',
                        $record->supplier_id === null => 'Belum ada supplier',
                        $record->is_consignment => 'Titipan grosir',
                        default => 'Dikirim supplier',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pembelian' => 'success',
                        'Belum ada supplier' => 'danger',
                        'Titipan grosir' => 'info',
                        default => 'gray',
                    }),
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
                SelectFilter::make('kind')
                    ->label('Jenis tagihan')
                    ->options(SupplierBillLine::KINDS),
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
                    ->label('Supplier')
                    ->trueLabel('Sudah ada supplier')
                    ->falseLabel('Belum ada supplier')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('supplier_id'),
                        false: fn (Builder $query) => $query->whereNull('supplier_id'),
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
