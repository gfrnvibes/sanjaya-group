<?php

namespace App\Filament\Resources\Tailors;

use App\Filament\Resources\Tailors\Pages\CreateTailor;
use App\Filament\Resources\Tailors\Pages\EditTailor;
use App\Filament\Resources\Tailors\Pages\ListTailors;
use App\Filament\Resources\Tailors\Pages\ViewTailor;
use App\Filament\Resources\Tailors\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Tailors\RelationManagers\WorkOrdersRelationManager;
use App\Filament\Resources\Tailors\Schemas\TailorForm;
use App\Filament\Resources\Tailors\Schemas\TailorInfolist;
use App\Filament\Resources\Tailors\Tables\TailorsTable;
use App\Models\Tailor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TailorResource extends Resource
{
    protected static ?string $model = Tailor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static string|UnitEnum|null $navigationGroup = 'Produksi';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Penjahit';

    protected static ?string $pluralModelLabel = 'Penjahit & Tukang Potong';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TailorForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TailorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TailorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WorkOrdersRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTailors::route('/'),
            'create' => CreateTailor::route('/create'),
            'view' => ViewTailor::route('/{record}'),
            'edit' => EditTailor::route('/{record}/edit'),
        ];
    }
}
