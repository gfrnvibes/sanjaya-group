<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data SPK')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('No. SPK')
                            ->default(fn (): string => 'SPK-'.now()->format('ymd').'-'.str_pad((string) (WorkOrder::query()->whereDate('created_at', today())->count() + 1), 2, '0', STR_PAD_LEFT))
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        DatePicker::make('cut_date')
                            ->label('Tanggal Potong')
                            ->default(today())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('warehouse_id')
                            ->label('Gudang (bahan & barang jadi)')
                            ->relationship('warehouse', 'name')
                            ->default(fn () => Warehouse::query()->value('id'))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('tailor_id')
                            ->label('Penjahit')
                            ->relationship('tailor', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->required(),
                                TextInput::make('phone')
                                    ->label('Telepon'),
                            ]),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                Section::make('Varian yang Dipotong')
                    ->description('Saat disimpan, bahan dikeluarkan dari stok sesuai resep (BOM) dan biaya per pcs dicatat sebagai snapshot.')
                    ->visibleOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Varian'),
                                TableColumn::make('Qty Potong'),
                            ])
                            ->schema([
                                Select::make('variant_id')
                                    ->options(fn () => ProductVariant::query()
                                        ->where('is_active', true)
                                        ->whereHas('model', fn (Builder $query) => $query->where('kind', 'produksi'))
                                        ->orderBy('sku')
                                        ->pluck('sku', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('qty_cut')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah varian'),
                    ]),
            ]);
    }
}
