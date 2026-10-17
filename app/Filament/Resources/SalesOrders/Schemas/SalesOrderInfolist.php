<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalesOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Pesanan')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('order_no')
                            ->label('No. Pesanan')
                            ->copyable(),
                        TextEntry::make('order_date')
                            ->label('Tanggal')
                            ->date(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (SalesOrderStatus $state): string => self::statusColor($state)),
                        TextEntry::make('buyer_name')
                            ->label('Pembeli')
                            ->placeholder('-'),
                        TextEntry::make('store.name')
                            ->label('Toko'),
                        TextEntry::make('store.channel.name')
                            ->label('Channel')
                            ->badge(),
                        TextEntry::make('store.reseller.name')
                            ->label('Reseller'),
                        TextEntry::make('store.reseller.owner.name')
                            ->label('Pemilik'),
                        TextEntry::make('warehouse.name')
                            ->label('Gudang'),
                        TextEntry::make('importBatch.file_name')
                            ->label('Batch Impor')
                            ->placeholder('Input manual'),
                        TextEntry::make('creator.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-'),
                    ]),
                Section::make('Nilai')
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('revenue_total')
                            ->label('Pendapatan Toko')
                            ->state(fn (SalesOrder $record): float => (float) $record->items()->sum('revenue'))
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('bill_total')
                            ->label('Tagihan Konveksi')
                            ->state(fn (SalesOrder $record): float => (float) $record->items()->sum('bill_amount'))
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('margin_total')
                            ->label('Laba Kotor Toko')
                            ->state(fn (SalesOrder $record): float => (float) $record->items()->sum('margin'))
                            ->money('IDR', decimalPlaces: 0)
                            ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger'),
                        TextEntry::make('platform_fee')
                            ->label('Potongan Admin')
                            ->money('IDR', decimalPlaces: 0)
                            ->placeholder('Estimasi % channel'),
                        TextEntry::make('shipping_fee')
                            ->label('Ongkir')
                            ->money('IDR', decimalPlaces: 0)
                            ->placeholder('-'),
                    ]),
            ]);
    }

    public static function statusColor(SalesOrderStatus $state): string
    {
        return match ($state) {
            SalesOrderStatus::Pending => 'warning',
            SalesOrderStatus::Completed => 'success',
            SalesOrderStatus::Returned => 'info',
            SalesOrderStatus::Cancelled => 'danger',
        };
    }
}
