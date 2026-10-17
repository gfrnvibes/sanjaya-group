<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockOpnameInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Opname')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('warehouse.name')
                            ->label('Gudang'),
                        TextEntry::make('counted_at')
                            ->label('Waktu Hitung')
                            ->dateTime(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn ($state): string => $state?->value === 'posted' ? 'success' : 'warning'),
                        TextEntry::make('posted_at')
                            ->label('Diposting')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Hasil Hitung')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('SKU'),
                                TableColumn::make('Stok Sistem'),
                                TableColumn::make('Stok Fisik'),
                                TableColumn::make('Selisih'),
                            ])
                            ->schema([
                                TextEntry::make('variant.sku'),
                                TextEntry::make('system_qty'),
                                TextEntry::make('counted_qty'),
                                TextEntry::make('diff')
                                    ->badge()
                                    ->color(fn ($state): string => (int) $state === 0 ? 'gray' : ((int) $state > 0 ? 'success' : 'danger')),
                            ]),
                    ]),
            ]);
    }
}
