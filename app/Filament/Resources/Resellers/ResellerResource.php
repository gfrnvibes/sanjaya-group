<?php

namespace App\Filament\Resources\Resellers;

use App\Filament\Resources\Resellers\Pages\CreateReseller;
use App\Filament\Resources\Resellers\Pages\EditReseller;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Filament\Resources\Resellers\Pages\ViewReseller;
use App\Filament\Resources\Resellers\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Resellers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Resellers\RelationManagers\StoresRelationManager;
use App\Filament\Resources\Resellers\Schemas\ResellerForm;
use App\Filament\Resources\Resellers\Schemas\ResellerInfolist;
use App\Filament\Resources\Resellers\Tables\ResellersTable;
use App\Models\Reseller;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ResellerResource extends Resource
{
    protected static ?string $model = Reseller::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Reseller & Toko';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Reseller';

    protected static ?string $pluralModelLabel = 'Reseller';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ResellerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResellerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResellersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StoresRelationManager::class,
            InvoicesRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellers::route('/'),
            'create' => CreateReseller::route('/create'),
            'view' => ViewReseller::route('/{record}'),
            'edit' => EditReseller::route('/{record}/edit'),
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
