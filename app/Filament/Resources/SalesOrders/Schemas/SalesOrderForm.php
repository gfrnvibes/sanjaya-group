<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Enums\SalesOrderStatus;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SalesOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Pesanan')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('store_id')
                            ->label('Toko')
                            ->relationship('store', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabledOn('edit')
                            ->columnSpan(2),
                        TextInput::make('order_no')
                            ->label('No. Pesanan')
                            ->default(fn (): string => 'ORD-'.now()->format('ymdHis'))
                            ->required()
                            ->maxLength(60)
                            ->disabledOn('edit'),
                        DatePicker::make('order_date')
                            ->label('Tanggal')
                            ->default(today())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('warehouse_id')
                            ->label('Gudang Asal')
                            ->relationship('warehouse', 'name')
                            ->default(fn () => Setting::valueOf('default_warehouse_id') ?? Warehouse::query()->value('id'))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                SalesOrderStatus::Completed->value => SalesOrderStatus::Completed->getLabel(),
                                SalesOrderStatus::Pending->value => SalesOrderStatus::Pending->getLabel(),
                            ])
                            ->default(SalesOrderStatus::Completed->value)
                            ->required()
                            ->visibleOn('create'),
                        TextInput::make('buyer_name')
                            ->label('Nama Pembeli')
                            ->maxLength(120),
                        TextInput::make('platform_fee')
                            ->label('Potongan Admin Aktual')
                            ->helperText('Kosong = pakai estimasi % channel.')
                            ->numeric()
                            ->prefix('Rp'),
                        TextInput::make('shipping_fee')
                            ->label('Ongkir')
                            ->numeric()
                            ->prefix('Rp'),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Produk')
                    ->description('Harga reseller, % tier, dan HPP diambil otomatis sesuai tanggal pesanan dan disimpan sebagai snapshot. Stok berkurang dari gudang asal.')
                    ->visibleOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Varian'),
                                TableColumn::make('Qty'),
                                TableColumn::make('Pendapatan Toko'),
                                TableColumn::make('SKU di Marketplace'),
                            ])
                            ->schema([
                                Select::make('variant_id')
                                    ->options(fn () => ProductVariant::query()->where('is_active', true)->orderBy('sku')->pluck('sku', 'id'))
                                    ->searchable()
                                    ->required(),
                                TextInput::make('qty')
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required(),
                                TextInput::make('revenue')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('Rp'),
                                TextInput::make('sku_raw')
                                    ->maxLength(120),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah produk'),
                    ]),
            ]);
    }
}
