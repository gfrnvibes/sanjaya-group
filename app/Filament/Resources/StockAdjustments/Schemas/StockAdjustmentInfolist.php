<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAdjustmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Penyesuaian')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('adjusted_at')
                            ->label('Waktu')
                            ->dateTime(),
                        TextEntry::make('warehouse.name')
                            ->label('Gudang'),
                        TextEntry::make('reason')
                            ->label('Alasan')
                            ->badge(),
                        TextEntry::make('creator.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
