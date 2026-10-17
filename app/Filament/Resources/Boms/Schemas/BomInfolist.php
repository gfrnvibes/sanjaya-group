<?php

namespace App\Filament\Resources\Boms\Schemas;

use App\Enums\BomLineType;
use App\Models\BomItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resep')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('model.name')
                            ->label('Model'),
                        TextEntry::make('size.code')
                            ->label('Ukuran')
                            ->placeholder('Semua ukuran'),
                        TextEntry::make('effective_from')
                            ->label('Berlaku Mulai')
                            ->date(),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-'),
                    ]),
                Section::make('Komponen')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Jenis'),
                                TableColumn::make('Komponen'),
                                TableColumn::make('Warna'),
                                TableColumn::make('Pemakaian / Pengali'),
                                TableColumn::make('Ongkos / pcs'),
                            ])
                            ->schema([
                                TextEntry::make('line_type')
                                    ->badge(),
                                TextEntry::make('component')
                                    ->state(fn (BomItem $record): ?string => $record->line_type === BomLineType::Labor
                                        ? $record->laborType?->name
                                        : ($record->material?->code ?? $record->materialType?->name)),
                                TextEntry::make('color_mode')
                                    ->placeholder('-'),
                                TextEntry::make('qty_per_unit')
                                    ->numeric(decimalPlaces: 2),
                                TextEntry::make('labor_rate')
                                    ->money('IDR', decimalPlaces: 0)
                                    ->placeholder('-'),
                            ]),
                    ]),
            ]);
    }
}
