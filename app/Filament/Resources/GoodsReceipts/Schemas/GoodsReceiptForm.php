<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Enums\GoodsReceiptSource;
use App\Models\GoodsReceipt;
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

class GoodsReceiptForm
{
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
                            ->default(fn (): string => 'BM-'.now()->format('ymd').'-'.str_pad((string) (GoodsReceipt::query()->whereDate('created_at', today())->count() + 1), 3, '0', STR_PAD_LEFT))
                            ->required()
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
                                TableColumn::make('HPP / pcs'),
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
                                    ->prefix('Rp'),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah barang'),
                    ]),
            ]);
    }
}
