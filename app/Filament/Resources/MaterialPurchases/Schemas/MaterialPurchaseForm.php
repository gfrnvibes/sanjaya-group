<?php

namespace App\Filament\Resources\MaterialPurchases\Schemas;

use App\Filament\Forms\Components\ResellerOffsetSelect;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
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
                            ->required(fn (Get $get): bool => $get('payment_method') === 'credit')
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
                            ->helperText('Khusus bahan baku. Pembelian produk jadi (beli jadi) diinput lewat Barang Masuk dengan sumber Beli Jadi.')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('ref_no')
                            ->label('No. Nota')
                            ->maxLength(60),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Pembayaran')
                    ->description('Menentukan jurnalnya: kas berkurang, utang supplier bertambah, atau piutang reseller berkurang.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('payment_method')
                            ->label('Dibayar dengan')
                            ->options(MaterialPurchase::PAYMENT_METHODS)
                            ->default('cash')
                            ->inline()
                            ->live()
                            ->required()
                            ->columnSpanFull(),
                        Select::make('cash_account_id')
                            ->label('Dari rekening / kas')
                            ->relationship('cashAccount', 'name')
                            ->preload()
                            ->placeholder('Kas belum ditentukan')
                            ->visible(fn (Get $get): bool => $get('payment_method') === 'cash'),
                        ResellerOffsetSelect::make()
                            ->label('Dibayar reseller (potongan biaya)')
                            ->required(fn (Get $get): bool => $get('payment_method') === 'reseller')
                            ->visible(fn (Get $get): bool => $get('payment_method') === 'reseller')
                            ->columnSpan(2),
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
                                TableColumn::make('Qty Beli (satuan beli)'),
                                TableColumn::make('Isi Aktual (otomatis, boleh diubah)'),
                                TableColumn::make('Total Harga'),
                            ])
                            ->schema([
                                Select::make('material_id')
                                    ->options(fn () => Material::query()->where('is_active', true)->orderBy('code')->get()
                                        ->mapWithKeys(fn (Material $material): array => [$material->id => $material->code.' ('.self::conversion($material).')'])->all())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBaseQty($get, $set)),
                                TextInput::make('qty_pack')
                                    ->numeric()
                                    ->suffix(fn (Get $get): ?string => Material::query()->find($get('material_id'))?->pack_uom)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBaseQty($get, $set)),
                                TextInput::make('qty_base')
                                    ->numeric()
                                    ->suffix(fn (Get $get): ?string => Material::query()->find($get('material_id'))?->base_uom->value)
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

    /**
     * Mis. "1 yard = 91,44 cm" / "1 kg = 400 cm".
     */
    private static function conversion(Material $material): string
    {
        $qty = rtrim(rtrim(number_format((float) $material->qty_per_pack, 3, ',', '.'), '0'), ',');

        return '1 '.($material->pack_uom ?: $material->base_uom->value).' = '.$qty.' '.$material->base_uom->value;
    }

    private static function fillBaseQty(Get $get, Set $set): void
    {
        $material = Material::query()->find($get('material_id'));

        if ($material && filled($get('qty_pack'))) {
            $set('qty_base', round((float) $get('qty_pack') * (float) $material->qty_per_pack, 3));
        }
    }
}
