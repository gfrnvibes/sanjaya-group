<?php

namespace App\Filament\Resources\SalesImportFailures\Pages;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Filament\Actions\SalesOrderImportActions;
use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use App\Models\SalesImportFailure;
use Filament\Resources\Pages\ListRecords;

class ListSalesImportFailures extends ListRecords
{
    protected static string $resource = SalesImportFailureResource::class;

    /**
     * Ringkasan baris yang belum diperbaiki per kategori alasan.
     */
    public function getSubheading(): ?string
    {
        $counts = SalesImportFailure::query()
            ->where('status', ImportFailureStatus::Open)
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason');

        if ($counts->isEmpty()) {
            return 'Semua baris gagal sudah ditangani.';
        }

        return 'Belum diperbaiki: '.$counts->sum().' baris — '.$counts
            ->map(fn (int $total, string $reason): string => (ImportFailureReason::tryFrom($reason)?->getLabel() ?? $reason)." {$total}")
            ->implode(', ')
            .'. Alur: Unduh baris gagal → perbaiki di Excel (atau perbaiki master datanya) → Import Excel.';
    }

    protected function getHeaderActions(): array
    {
        return [
            SalesOrderImportActions::importAction()->label('Import ulang (file yang sudah diperbaiki)'),
        ];
    }
}
