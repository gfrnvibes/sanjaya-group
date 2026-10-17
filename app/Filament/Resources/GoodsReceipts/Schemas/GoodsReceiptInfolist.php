<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Models\GoodsReceipt;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GoodsReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Barang Masuk')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('receipt_no')
                            ->label('No. Dokumen'),
                        TextEntry::make('received_at')
                            ->label('Waktu Terima')
                            ->dateTime(),
                        TextEntry::make('warehouse.name')
                            ->label('Gudang'),
                        TextEntry::make('source')
                            ->label('Sumber')
                            ->badge(),
                        TextEntry::make('supplier.name')
                            ->label('Supplier')
                            ->placeholder('-'),
                        TextEntry::make('total_qty')
                            ->label('Total Qty')
                            ->state(fn (GoodsReceipt $record): int => (int) $record->items()->sum('qty')),
                        TextEntry::make('creator.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
