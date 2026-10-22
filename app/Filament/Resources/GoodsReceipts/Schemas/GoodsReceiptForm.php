<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Enums\GoodsReceiptSource;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class GoodsReceiptForm
{
    private static function isReadyMade(mixed $source): bool
    {
        return ($source instanceof GoodsReceiptSource ? $source : GoodsReceiptSource::tryFrom((string) $source)) === GoodsReceiptSource::BeliJadi;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Barang Masuk')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('receipt_no')
                            ->label('No. Dokumen')
                            ->helperText('Kosong = nomor otomatis saat disimpan.')
                            ->maxLength(40)
                            ->unique(ignoreRecord: true),
                        DateTimePicker::make('received_at')
                            ->label('Waktu Terima')
                            ->default(now())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->relationship('warehouse', 'name', fn ($query) => $query->forGoods())
                            ->default(fn () => Warehouse::defaultForGoods()?->id)
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('source')
                            ->label('Sumber')
                            ->options(GoodsReceiptSource::class)
                            ->default(GoodsReceiptSource::Borongan)
                            ->helperText('Beli Jadi: isi harga beli per pcs. HPP & harga reseller produk beli jadi dihitung otomatis (harga beli + packing + margin beli jadi).')
                            ->live()
                            ->required(),
                        Select::make('supplier_id')
                            ->label('Supplier / Penjahit Borongan')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload(),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Barang')
                    ->description('Setelah disimpan, stok bertambah dan baris tidak bisa diubah (koreksi lewat Penyesuaian).')
                    ->visibleOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Varian'),
                                TableColumn::make('Qty'),
                                TableColumn::make('Harga Beli / HPP per pcs'),
                            ])
                            ->schema([
                                Select::make('variant_id')
                                    ->options(fn () => ProductVariant::query()->where('is_active', true)->orderBy('sku')->pluck('sku', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('qty')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),
                                TextInput::make('unit_cost')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(fn (Get $get): bool => self::isReadyMade($get('../../source')))
                                    ->prefix('Rp'),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah barang'),
                    ]),
            ]);
    }
}
