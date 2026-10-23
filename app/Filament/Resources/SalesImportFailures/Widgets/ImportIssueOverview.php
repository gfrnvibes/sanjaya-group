<?php

namespace App\Filament\Resources\SalesImportFailures\Widgets;

use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use App\Imports\ImportRegistry;
use App\Services\SkuMapping;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Notifikasi ringkas di atas Import Perlu Dicek: total belum diperbaiki, kategori terbanyak,
 * baris baru 24 jam terakhir, dan SKU yang bisa dipetakan otomatis.
 */
class ImportIssueOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $open = SalesImportFailureResource::getEloquentQuery()->where('status', ImportFailureStatus::Open)->where('line', '>', 0);
        $total = $open->clone()->count();
        $perCategory = $open->clone()->selectRaw('importer, count(*) as total')->groupBy('importer')->orderByDesc('total')->pluck('total', 'importer');
        $recent = $open->clone()->where('created_at', '>=', now()->subDay())->count();

        $stats = [
            Stat::make('Belum diperbaiki', number_format($total, 0, ',', '.'))
                ->description($total === 0 ? 'Semua import sudah sesuai' : $perCategory->count().' kategori data')
                ->descriptionIcon($total === 0 ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($total === 0 ? 'success' : 'danger'),
            Stat::make('Kategori terbanyak', $perCategory->isEmpty() ? '-' : ImportRegistry::label((string) $perCategory->keys()->first()))
                ->description($perCategory->take(3)->map(fn (int $count, string $key): string => ImportRegistry::label($key).' '.$count)->implode(' · ') ?: 'Tidak ada')
                ->color('warning'),
            Stat::make('Baru 24 jam terakhir', (string) $recent)
                ->description($recent > 0 ? 'Baris gagal dari import terbaru' : 'Tidak ada baris baru')
                ->descriptionIcon(Heroicon::OutlinedBellAlert)
                ->color($recent > 0 ? 'danger' : 'gray'),
        ];

        if (ImportRegistry::allows(ImportRegistry::SALES_ORDERS)) {
            $mapping = app(SkuMapping::class);
            $missing = $mapping->openSkuFailures()->count();
            $auto = $missing === 0 ? 0 : count($mapping->plan());

            $stats[] = Stat::make('SKU belum dipetakan', (string) $missing)
                ->description($auto > 0 ? "{$auto} SKU bisa dipetakan otomatis (Terapkan saran SKU)" : 'Gunakan Petakan SKU pada baris')
                ->descriptionIcon(Heroicon::OutlinedSparkles)
                ->color($missing === 0 ? 'success' : ($auto > 0 ? 'info' : 'warning'));
        }

        return $stats;
    }

    protected function getColumns(): int|array|null
    {
        return 4;
    }
}
