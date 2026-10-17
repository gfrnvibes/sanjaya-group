<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Filament\Resources\ProductVariants\RelationManagers\CostsRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\PriceHistoriesRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\SkuAliasesRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\StockBalancesRelationManager;
use App\Filament\Resources\ProductVariants\RelationManagers\StockMovementsRelationManager;
use App\Filament\Resources\ProductVariants\Schemas\ProductVariantForm;
use App\Filament\Resources\ProductVariants\Schemas\ProductVariantInfolist;
use App\Filament\Resources\ProductVariants\Tables\ProductVariantsTable;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Produk';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Varian / SKU';

    protected static ?string $pluralModelLabel = 'Varian / SKU';

    protected static ?string $recordTitleAttribute = 'sku';

    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'name'];
    }

    public static function form(Schema $schema): Schema
    {
        return ProductVariantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductVariantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductVariantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StockBalancesRelationManager::class,
            CostsRelationManager::class,
            PriceHistoriesRelationManager::class,
            SkuAliasesRelationManager::class,
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductVariants::route('/'),
            'create' => CreateProductVariant::route('/create'),
            'view' => ViewProductVariant::route('/{record}'),
            'edit' => EditProductVariant::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
