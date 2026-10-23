<?php

namespace App\Filament\Resources\SalesImportFailures;

use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Filament\Resources\SalesImportFailures\Tables\SalesImportFailuresTable;
use App\Imports\ImportRegistry;
use App\Models\SalesImportFailure;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * IMPORT PERLU DICEK: pusat perbaikan baris import yang tidak sesuai dari SEMUA jenis data.
 * Ditangani per kategori data (tab): edit langsung lalu coba ulang, atau unduh template kategori -> perbaiki -> import.
 * Hanya kategori yang boleh dikelola user (izin importer-nya) yang tampil.
 */
class SalesImportFailureResource extends Resource
{
    protected static ?string $model = SalesImportFailure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Import Perlu Dicek';

    protected static ?string $modelLabel = 'Baris Perlu Dicek';

    protected static ?string $pluralModelLabel = 'Import Perlu Dicek';

    protected static ?string $slug = 'import-perlu-dicek';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return Builder<SalesImportFailure>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('importer', ImportRegistry::accessibleKeys());
    }

    public static function openCount(): int
    {
        return static::getEloquentQuery()->where('status', ImportFailureStatus::Open)->where('line', '>', 0)->count();
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::openCount();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Baris import yang belum diperbaiki (semua kategori data)';
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
