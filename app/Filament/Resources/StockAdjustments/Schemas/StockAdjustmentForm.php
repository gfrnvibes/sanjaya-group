<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentReason;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Penyesuaian')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        DateTimePicker::make('adjusted_at')
                            ->label('Waktu')
                            ->default(now())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->relationship('warehouse', 'name')
                            ->default(fn () => Warehouse::query()->value('id'))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('reason')
                            ->label('Alasan')
                            ->options(StockAdjustmentReason::class)
                            ->required()
                            ->disabledOn('edit'),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Barang')
                    ->description('Qty positif = tambah stok, negatif = kurangi stok (rusak, sampel, dll).')
                    ->visibleOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Varian'),
                                TableColumn::make('Qty (+/-)'),
                            ])
                            ->schema([
                                Select::make('variant_id')
                                    ->options(fn () => ProductVariant::query()->orderBy('sku')->pluck('sku', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('qty')
                                    ->integer()
                                    ->required()
                                    ->notIn([0]),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah barang'),
                    ]),
            ]);
    }
}
