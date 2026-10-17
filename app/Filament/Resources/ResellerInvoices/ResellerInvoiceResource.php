<?php

namespace App\Filament\Resources\ResellerInvoices;

use App\Enums\ResellerInvoiceStatus;
use App\Filament\Resources\ResellerInvoices\Pages\CreateResellerInvoice;
use App\Filament\Resources\ResellerInvoices\Pages\EditResellerInvoice;
use App\Filament\Resources\ResellerInvoices\Pages\ListResellerInvoices;
use App\Filament\Resources\ResellerInvoices\Pages\ViewResellerInvoice;
use App\Filament\Resources\ResellerInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ResellerInvoices\RelationManagers\ReturnsRelationManager;
use App\Filament\Resources\ResellerInvoices\Schemas\ResellerInvoiceForm;
use App\Filament\Resources\ResellerInvoices\Schemas\ResellerInvoiceInfolist;
use App\Filament\Resources\ResellerInvoices\Tables\ResellerInvoicesTable;
use App\Models\ResellerInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ResellerInvoiceResource extends Resource
{
    protected static ?string $model = ResellerInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Tagihan Reseller';

    protected static ?string $pluralModelLabel = 'Tagihan Reseller';

    protected static ?string $recordTitleAttribute = 'invoice_no';

    public static function form(Schema $schema): Schema
    {
        return ResellerInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResellerInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResellerInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            ReturnsRelationManager::class,
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === ResellerInvoiceStatus::Draft;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellerInvoices::route('/'),
            'create' => CreateResellerInvoice::route('/create'),
            'view' => ViewResellerInvoice::route('/{record}'),
            'edit' => EditResellerInvoice::route('/{record}/edit'),
        ];
    }
}
