<?php

namespace App\Filament\Resources\Materials\Schemas;

use App\Models\Material;
use App\Services\CostingService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MaterialInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Bahan')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Kode'),
                        TextEntry::make('materialType.name')
                            ->label('Jenis'),
                        TextEntry::make('color.name')
                            ->label('Warna')
                            ->placeholder('-'),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('qty_per_pack')
                            ->label('Isi per Satuan Beli')
                            ->formatStateUsing(fn ($state, Material $record): string => number_format((float) $state, 0, ',', '.')." {$record->base_uom->value} / {$record->pack_uom}"),
                        TextEntry::make('stock_total')
                            ->label('Total Stok')
                            ->state(fn (Material $record): float => (float) $record->balances()->sum('qty'))
                            ->formatStateUsing(fn ($state, Material $record): string => number_format((float) $state, 0, ',', '.')." {$record->base_uom->value}")
                            ->color(fn ($state, Material $record): string => (float) $state <= (float) $record->min_stock ? 'danger' : 'success')
                            ->weight('bold'),
                        TextEntry::make('min_stock')
                            ->label('Stok Minimum')
                            ->formatStateUsing(fn ($state, Material $record): string => number_format((float) $state, 0, ',', '.')." {$record->base_uom->value}"),
                        TextEntry::make('last_unit_cost')
                            ->label('Harga Beli Terakhir / Satuan Dasar')
                            ->state(fn (Material $record): string => app(CostingService::class)->latestUnitCost($record, today()))
                            ->money('IDR', decimalPlaces: 2),
                    ]),
            ]);
    }
}
