<?php

namespace App\Filament\Resources\MaterialPurchases\Schemas;

use App\Models\MaterialPurchase;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MaterialPurchaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Pembelian')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('purchase_date')
                            ->label('Tanggal')
                            ->date(),
                        TextEntry::make('supplier.name')
                            ->label('Supplier')
                            ->placeholder('-'),
                        TextEntry::make('warehouse.name')
                            ->label('Gudang'),
                        TextEntry::make('ref_no')
                            ->label('No. Nota')
                            ->placeholder('-'),
                        TextEntry::make('total')
                            ->label('Total Pembelian')
                            ->state(fn (MaterialPurchase $record): float => (float) $record->items()->sum('total_price'))
                            ->money('IDR', decimalPlaces: 0)
                            ->weight('bold'),
                        TextEntry::make('creator.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpan(2),
                    ]),
            ]);
    }
}
