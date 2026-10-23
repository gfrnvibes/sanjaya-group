<?php

namespace App\Filament\Resources\SupplierWholesalePrices;

use App\Filament\Resources\SupplierWholesalePrices\Pages\ManageSupplierWholesalePrices;
use App\Models\SupplierWholesalePrice;
use App\Services\SalesOrderImporter;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HARGA GROSIR BORDIR per supplier per model: dipakai otomatis bila total qty SKU yang sama dalam 1 pesanan >= min. qty.
 * Pesanan grosir = titipan: reseller membayar persis harga ini dan seluruhnya diteruskan ke supplier.
 */
class SupplierWholesalePriceResource extends Resource
{
    protected static ?string $model = SupplierWholesalePrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Produk';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Harga Grosir Bordir';

    protected static ?string $modelLabel = 'Harga Grosir';

    protected static ?string $pluralModelLabel = 'Harga Grosir Bordir';

    protected static ?string $slug = 'harga-grosir-bordir';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('model_id')
                    ->label('Model produk')
                    ->relationship('model', 'name', fn (Builder $query) => $query->where('kind', 'beli_jadi'))
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->code} — {$record->name}")
                    ->searchable(['code', 'name'])
                    ->preload()
                    ->helperText('Hanya model berjenis Beli Jadi. Berlaku untuk semua varian (warna/ukuran) model ini.')
                    ->required(),
                TextInput::make('min_qty')
                    ->label('Minimal qty (per SKU per pesanan)')
                    ->integer()
                    ->minValue(SalesOrderImporter::WHOLESALE_MIN_QTY)
                    ->default(SalesOrderImporter::WHOLESALE_MIN_QTY)
                    ->required(),
                TextInput::make('unit_price')
                    ->label('Harga grosir / pcs')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->helperText('Harga ke reseller = harga ini (tanpa laba konveksi), diteruskan utuh ke supplier.')
                    ->required(),
                DatePicker::make('effective_from')
                    ->label('Berlaku mulai')
                    ->default(today())
                    ->required(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'model']))
            ->defaultSort(fn (Builder $query) => $query->orderBy('model_id')->orderBy('min_qty')->orderByDesc('effective_from'))
            ->columns([
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('model.name')->label('Model')->description(fn (SupplierWholesalePrice $record): ?string => $record->model?->code)->searchable(),
                TextColumn::make('min_qty')->label('Min. qty')->formatStateUsing(fn (int $state): string => "≥ {$state} pcs")->alignEnd(),
                TextColumn::make('unit_price')->label('Harga / pcs')->money('IDR', decimalPlaces: 0)->alignEnd(),
                TextColumn::make('effective_from')->label('Berlaku mulai')->date()->sortable(),
                TextColumn::make('note')->label('Catatan')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier')->label('Supplier')->relationship('supplier', 'name')->preload(),
                SelectFilter::make('model')->label('Model')->relationship('model', 'name')->searchable()->preload(),
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
            'index' => ManageSupplierWholesalePrices::route('/'),
        ];
    }
}
