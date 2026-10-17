<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Models\Warehouse;
use App\Services\StockLedger;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Opname')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->relationship('warehouse', 'name')
                            ->default(fn () => Warehouse::query()->value('id'))
                            ->required()
                            ->live(),
                        DateTimePicker::make('counted_at')
                            ->label('Waktu Hitung')
                            ->default(now())
                            ->required(),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Hasil Hitung')
                    ->description('Stok sistem diambil ulang saat posting. Selisih (fisik - sistem) dicatat ke kartu stok.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Varian'),
                                TableColumn::make('Stok Sistem'),
                                TableColumn::make('Stok Fisik'),
                            ])
                            ->schema([
                                Select::make('variant_id')
                                    ->relationship('variant', 'sku')
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                        if ($state && $get('../../warehouse_id')) {
                                            $set('system_qty', app(StockLedger::class)->stockQty((int) $state, (int) $get('../../warehouse_id')));
                                        }
                                    }),
                                TextInput::make('system_qty')
                                    ->integer()
                                    ->default(0)
                                    ->readOnly()
                                    ->required(),
                                TextInput::make('counted_qty')
                                    ->integer()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Tambah varian'),
                    ]),
            ]);
    }
}
