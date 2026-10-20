<?php

namespace App\Filament\Resources\SalesReturns;

use App\Enums\ReturnReason;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\SalesReturns\Pages\ManageSalesReturns;
use App\Models\SalesReturn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SalesReturnResource extends Resource
{
    protected static ?string $model = SalesReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Retur';

    protected static ?string $pluralModelLabel = 'Retur Penjualan';

    /**
     * Retur dibuat dari halaman pesanan (aksi Retur per baris) agar stok & tagihan konsisten.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('reason')
                    ->label('Alasan Retur')
                    ->options(ReturnReason::class)
                    ->required(),
                Toggle::make('credit_reseller')
                    ->label('Kurangi tagihan reseller')
                    ->disabled(fn (SalesReturn $record): bool => $record->reseller_invoice_id !== null),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'salesOrderItem.variant',
                'salesOrderItem.salesOrder.store',
                'warehouse',
                'invoice',
            ]))
            ->defaultSort('returned_at', 'desc')
            ->columns([
                TextColumn::make('returned_at')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('salesOrderItem.salesOrder.order_no')
                    ->label('No. Pesanan')
                    ->searchable(),
                TextColumn::make('salesOrderItem.salesOrder.store.name')
                    ->label('Toko'),
                TextColumn::make('salesOrderItem.variant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->placeholder('Belum diisi'),
                TextColumn::make('credit_amount')
                    ->label('Nilai Kredit')
                    ->state(fn (SalesReturn $record): float => $record->credit_reseller ? $record->qty * (float) $record->salesOrderItem->reseller_unit_price : 0)
                    ->money('IDR', decimalPlaces: 0),
                IconColumn::make('restock')
                    ->label('Restock')
                    ->boolean(),
                TextColumn::make('warehouse.name')
                    ->label('Gudang')
                    ->placeholder('-'),
                IconColumn::make('credit_reseller')
                    ->label('Kredit')
                    ->boolean(),
                TextColumn::make('invoice.invoice_no')
                    ->label('Tagihan')
                    ->placeholder('Belum'),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->label('Alasan')
                    ->options(ReturnReason::class)
                    ->multiple(),
                TernaryFilter::make('restock')
                    ->label('Restock'),
                TernaryFilter::make('credit_reseller')
                    ->label('Kredit reseller'),
            ])
            ->recordActions([
                Action::make('openOrder')
                    ->label('Pesanan')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->url(fn (SalesReturn $record): string => SalesOrderResource::getUrl('view', ['record' => $record->salesOrderItem->sales_order_id])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalesReturns::route('/'),
        ];
    }
}
