<?php

namespace App\Filament\Resources\SalesImportFailures;

use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Filament\Resources\SalesImportFailures\Tables\SalesImportFailuresTable;
use App\Models\SalesImportFailure;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Antrian perbaikan import pesanan: baris gagal + alasan + cara memperbaiki.
 */
class SalesImportFailureResource extends Resource
{
    protected static ?string $model = SalesImportFailure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Import Gagal';

    protected static ?string $modelLabel = 'Baris Gagal';

    protected static ?string $pluralModelLabel = 'Import Pesanan Gagal';

    protected static ?string $slug = 'import-gagal';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SalesImportFailure::query()->where('status', ImportFailureStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Baris import pesanan yang belum diperbaiki';
    }

    public static function table(Table $table): Table
    {
        return SalesImportFailuresTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesImportFailures::route('/'),
        ];
    }
}
