<?php

namespace App\Filament\Resources\MaterialPurchases;

use App\Filament\Resources\MaterialPurchases\Pages\CreateMaterialPurchase;
use App\Filament\Resources\MaterialPurchases\Pages\EditMaterialPurchase;
use App\Filament\Resources\MaterialPurchases\Pages\ListMaterialPurchases;
use App\Filament\Resources\MaterialPurchases\Pages\ViewMaterialPurchase;
use App\Filament\Resources\MaterialPurchases\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\MaterialPurchases\Schemas\MaterialPurchaseForm;
use App\Filament\Resources\MaterialPurchases\Schemas\MaterialPurchaseInfolist;
use App\Filament\Resources\MaterialPurchases\Tables\MaterialPurchasesTable;
use App\Models\MaterialPurchase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MaterialPurchaseResource extends Resource
{
    protected static ?string $model = MaterialPurchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Pembelian Bahan';

    protected static ?string $pluralModelLabel = 'Pembelian Bahan';

    protected static ?string $recordTitleAttribute = 'ref_no';

    public static function form(Schema $schema): Schema
    {
        return MaterialPurchaseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MaterialPurchaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialPurchasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    /**
     * Pembelian sudah tercatat di ledger bahan; koreksi lewat penyesuaian bahan.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialPurchases::route('/'),
            'create' => CreateMaterialPurchase::route('/create'),
            'view' => ViewMaterialPurchase::route('/{record}'),
            'edit' => EditMaterialPurchase::route('/{record}/edit'),
        ];
    }
}
