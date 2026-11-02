<?php

namespace App\Filament\Pages\Reports;

use App\Services\FinancialStatements;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * LAPORAN LABA RUGI: penjualan bersih - HPP = laba kotor - beban = laba bersih (dikurangi pajak bila tarif pajak diaktifkan).
 */
class IncomeStatement extends FinancialReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?string $slug = 'laporan-keuangan/laba-rugi';

    protected function reportTitle(): string
    {
        return 'Laporan Laba Rugi';
    }

    protected function rows(string $entity, Carbon $from, Carbon $until): array
    {
        return app(FinancialStatements::class)->incomeStatement($entity, $from, $until)['rows'];
    }

    protected function notes(): array
    {
        return [
            'Konveksi: penjualan diakui pada tanggal pesanan, retur pada tanggal retur; titipan grosir (tanpa laba) tidak dihitung. Angka % di bawah laba = margin terhadap penjualan bersih.',
            FinancialStatements::taxEnabled()
                ? 'Pajak = '.FinancialStatements::percent(FinancialStatements::taxRate()).' dari laba kumulatif tahun berjalan, dijurnal tiap akhir bulan (bulan rugi mengoreksi pajak bulan sebelumnya). Ubah tarif di Pengaturan: tax_rate.'
                : 'Pajak belum dihitung (Pengaturan: tax_rate = 0).',
            'HPP produksi = biaya bahan + ongkos SPK; selisih produksi = bahan & ongkos barang reject / SPK batal; selisih persediaan = hasil opname & penyesuaian stok.',
            ...parent::notes(),
        ];
    }
}
