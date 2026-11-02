<?php

namespace App\Filament\Pages\Reports;

use App\Services\FinancialStatements;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * LAPORAN ARUS KAS (metode langsung): saldo awal + operasi + investasi + pendanaan = saldo akhir, plus saldo per rekening.
 */
class CashFlowStatement extends FinancialReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Arus Kas';

    protected static ?string $title = 'Laporan Arus Kas';

    protected static ?string $slug = 'laporan-keuangan/arus-kas';

    protected function reportTitle(): string
    {
        return 'Laporan Arus Kas';
    }

    protected function rows(string $entity, Carbon $from, Carbon $until): array
    {
        return app(FinancialStatements::class)->cashFlow($entity, $from, $until)['rows'];
    }

    protected function notes(): array
    {
        return [
            'Metode langsung dari akun kas di buku besar. Saldo awal kas per rekening dicatat lewat Jurnal Umum (Kas / Saldo Awal); tanpa itu saldo bisa minus.',
            'Transaksi tanpa rekening (mis. barang masuk tunai) masuk akun "Kas Belum Ditentukan Rekening". Belanja/upah yang dibayar reseller bukan arus kas (memotong piutang).',
            ...parent::notes(),
        ];
    }
}
