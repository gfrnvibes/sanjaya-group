<?php

namespace App\Filament\Resources\Warehouses;

use App\Enums\WarehouseKind;
use App\Filament\Resources\Warehouses\Pages\ManageWarehouses;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'Gudang';

    protected static ?string $pluralModelLabel = 'Gudang';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(80),
                Select::make('kind')
                    ->label('Jenis')
                    ->options(WarehouseKind::class)
                    ->default(WarehouseKind::Campuran)
                    ->helperText('Bahan Baku: hanya stok bahan. Barang Jadi: hanya stok produk. Campuran: keduanya.')
                    ->required(),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withSum('stockBalances as stock_qty', 'qty')
                ->withCount('materialBalances'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('stock_qty')
                    ->label('Total Stok Barang (pcs)')
                    ->numeric(),
                TextColumn::make('material_balances_count')
                    ->label('Jenis Bahan Tersimpan'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWarehouses::route('/'),
        ];
    }
}
