<?php

namespace App\Filament\Pages\Reports;

use App\Services\FinancialStatements;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * LAPORAN PERUBAHAN MODAL: modal awal + setoran - prive + laba bersih (+ selisih) = modal akhir.
 */
class EquityStatement extends FinancialReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Perubahan Modal';

    protected static ?string $title = 'Laporan Perubahan Modal';

    protected static ?string $slug = 'laporan-keuangan/perubahan-modal';

    protected function reportTitle(): string
    {
        return 'Laporan Perubahan Modal';
    }

    protected function rows(string $entity, Carbon $from, Carbon $until): array
    {
        return app(FinancialStatements::class)->equityStatement($entity, $from, $until)['rows'];
    }

    protected function notes(): array
    {
        return [
            'Modal = total aset - total kewajiban pada neraca. Setoran & prive dicatat di Keuangan > Modal & Prive; saldo awal lain lewat Akuntansi > Jurnal Umum.',
            ...parent::notes(),
        ];
    }
}
