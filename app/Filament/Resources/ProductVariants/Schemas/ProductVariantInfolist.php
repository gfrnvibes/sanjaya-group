<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Models\ProductVariant;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductVariantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Varian')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('sku')
                            ->label('SKU')
                            ->copyable(),
                        TextEntry::make('name')
                            ->label('Nama')
                            ->columnSpan(2),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('model.name')
                            ->label('Model'),
                        TextEntry::make('color.name')
                            ->label('Warna')
                            ->placeholder('-'),
                        TextEntry::make('size.code')
                            ->label('Ukuran')
                            ->placeholder('-'),
                        TextEntry::make('min_stock')
                            ->label('Stok Minimum'),
                    ]),
                Section::make('Stok, HPP & Harga Berlaku')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('stock_total')
                            ->label('Total Stok (pcs)')
                            ->state(fn (ProductVariant $record): int => (int) $record->stockBalances()->sum('qty'))
                            ->color(fn (ProductVariant $record, $state): string => $state <= $record->min_stock ? 'danger' : 'success')
                            ->weight('bold'),
                        TextEntry::make('pricing.hpp')
                            ->label('HPP Berlaku')
                            ->money('IDR', decimalPlaces: 0)
                            ->placeholder('Belum ada'),
                        TextEntry::make('pricing.current_price')
                            ->label('Harga Dasar Berlaku')
                            ->money('IDR', decimalPlaces: 0)
                            ->placeholder('Belum ada'),
                        TextEntry::make('pricing.proposed_price')
                            ->label('Usulan Harga')
                            ->money('IDR', decimalPlaces: 0)
                            ->helperText(fn (ProductVariant $record): ?string => $record->pricing
                                ? 'Margin '.number_format((float) $record->pricing->margin_pct * 100, 2, ',', '.').'%'
                                : null)
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
