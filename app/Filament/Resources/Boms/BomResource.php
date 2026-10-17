<?php

namespace App\Filament\Resources\Boms;

use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Filament\Resources\Boms\Pages\ViewBom;
use App\Filament\Resources\Boms\Schemas\BomForm;
use App\Filament\Resources\Boms\Schemas\BomInfolist;
use App\Filament\Resources\Boms\Tables\BomsTable;
use App\Models\Bom;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BomResource extends Resource
{
    protected static ?string $model = Bom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Resep (BOM)';

    protected static ?string $pluralModelLabel = 'Resep (BOM)';

    public static function form(Schema $schema): Schema
    {
        return BomForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BomInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBoms::route('/'),
            'create' => CreateBom::route('/create'),
            'view' => ViewBom::route('/{record}'),
            'edit' => EditBom::route('/{record}/edit'),
        ];
    }
}
