<?php

namespace App\Filament\Resources\SalesImportBatches;

use App\Filament\Resources\SalesImportBatches\Pages\ListSalesImportBatches;
use App\Filament\Resources\SalesImportBatches\Pages\ViewSalesImportBatch;
use App\Filament\Resources\SalesImportBatches\RelationManagers\SalesOrdersRelationManager;
use App\Filament\Resources\SalesImportBatches\Schemas\SalesImportBatchInfolist;
use App\Filament\Resources\SalesImportBatches\Tables\SalesImportBatchesTable;
use App\Models\SalesImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SalesImportBatchResource extends Resource
{
    protected static ?string $model = SalesImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Batch Impor';

    protected static ?string $pluralModelLabel = 'Riwayat Impor';

    protected static ?string $recordTitleAttribute = 'file_name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesImportBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesImportBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesImportBatches::route('/'),
            'view' => ViewSalesImportBatch::route('/{record}'),
        ];
    }
}
