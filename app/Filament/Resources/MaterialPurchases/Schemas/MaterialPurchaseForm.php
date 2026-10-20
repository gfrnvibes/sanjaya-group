<?php

namespace App\Filament\Resources\MaterialPurchases\Schemas;

use App\Models\Material;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MaterialPurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Pembelian')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('purchase_date')
                            ->label('Tanggal Beli')
                            ->default(today())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->required(),
                                TextInput::make('phone')
                                    ->label('Telepon'),
                            ]),
                        Select::make('warehouse_id')
                            ->label('Gudang Tujuan')
                            ->relationship('warehouse', 'name', fn ($query) => $query->forMaterials())
                            ->default(fn () => Warehouse::defaultForMaterials()?->id)
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('ref_no')
                            ->label('No. Nota')
                            ->maxLength(60),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Bahan yang Dibeli')
                    ->description('Setelah disimpan, stok bahan bertambah dan baris tidak bisa diubah (koreksi lewat penyesuaian bahan).')
                    ->visibleOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Bahan'),
                                TableColumn::make('Qty Beli'),
                                TableColumn::make('Isi Aktual (satuan dasar)'),
                                TableColumn::make('Total Harga'),
                            ])
                            ->schema([
                                Select::make('material_id')
                                    ->options(fn () => Material::query()->where('is_active', true)->orderBy('code')->pluck('code', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBaseQty($get, $set)),
                                TextInput::make('qty_pack')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBaseQty($get, $set)),
                                TextInput::make('qty_base')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->required(),
                                TextInput::make('total_price')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('Rp')
                                    ->required(),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah bahan'),
                    ]),
            ]);
    }

    private static function fillBaseQty(Get $get, Set $set): void
    {
        $material = Material::query()->find($get('material_id'));

        if ($material && filled($get('qty_pack'))) {
            $set('qty_base', round((float) $get('qty_pack') * (float) $material->qty_per_pack, 3));
        }
    }
}
