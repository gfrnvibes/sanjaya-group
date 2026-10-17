<?php

namespace App\Filament\Resources\ProductModels;

use App\Filament\Resources\ProductModels\Pages\CreateProductModel;
use App\Filament\Resources\ProductModels\Pages\EditProductModel;
use App\Filament\Resources\ProductModels\Pages\ListProductModels;
use App\Filament\Resources\ProductModels\Pages\ViewProductModel;
use App\Filament\Resources\ProductModels\RelationManagers\BomsRelationManager;
use App\Filament\Resources\ProductModels\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\ProductModels\Schemas\ProductModelForm;
use App\Filament\Resources\ProductModels\Schemas\ProductModelInfolist;
use App\Filament\Resources\ProductModels\Tables\ProductModelsTable;
use App\Models\ProductModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProductModelResource extends Resource
{
    protected static ?string $model = ProductModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Produk';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Model Produk';

    protected static ?string $pluralModelLabel = 'Model Produk';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProductModelForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductModelInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductModelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            BomsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductModels::route('/'),
            'create' => CreateProductModel::route('/create'),
            'view' => ViewProductModel::route('/{record}'),
            'edit' => EditProductModel::route('/{record}/edit'),
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
