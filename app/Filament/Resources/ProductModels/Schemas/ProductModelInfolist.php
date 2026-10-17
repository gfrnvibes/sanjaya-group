<?php

namespace App\Filament\Resources\ProductModels\Schemas;

use App\Models\ProductModel;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductModelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Model')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Kode'),
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('category.name')
                            ->label('Kategori'),
                        TextEntry::make('kind')
                            ->label('Jenis')
                            ->badge(),
                        TextEntry::make('default_margin_pct')
                            ->label('Margin Default')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                            ->placeholder('Ikut pengaturan'),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('variants_count')
                            ->label('Jumlah Varian')
                            ->state(fn (ProductModel $record): int => $record->variants()->count()),
                        TextEntry::make('stock_total')
                            ->label('Total Stok (pcs)')
                            ->state(fn (ProductModel $record): int => (int) $record->variants()
                                ->join('stock_balances', 'stock_balances.variant_id', '=', 'product_variants.id')
                                ->sum('stock_balances.qty'))
                            ->numeric(),
                        TextEntry::make('deleted_at')
                            ->label('Dihapus')
                            ->dateTime()
                            ->visible(fn (ProductModel $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
