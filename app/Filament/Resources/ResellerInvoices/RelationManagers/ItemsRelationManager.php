<?php

namespace App\Filament\Resources\ResellerInvoices\RelationManagers;

use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrderItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Baris Penjualan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['variant', 'salesOrder.store']))
            ->columns([
                TextColumn::make('salesOrder.order_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('salesOrder.order_no')
                    ->label('No. Pesanan')
                    ->searchable(),
                TextColumn::make('salesOrder.store.name')
                    ->label('Toko'),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->description(fn (SalesOrderItem $record): ?string => $record->is_consignment ? 'Titipan grosir bordir (harga supplier)' : null)
                    ->searchable(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('reseller_unit_price')
                    ->label('Harga')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('bill_amount')
                    ->label('Tagihan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->recordActions([
                Action::make('openOrder')
                    ->label('Pesanan')
                    ->url(fn (SalesOrderItem $record): string => SalesOrderResource::getUrl('view', ['record' => $record->sales_order_id])),
            ]);
    }
}
