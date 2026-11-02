<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\BalanceSheet;
use App\Services\BalanceSheetService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Ringkasan neraca konveksi hari ini (1 baris); rincian & semua toko di halaman Neraca Keuangan.
 */
class FinancialPosition extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 2;

    /** Neraca cukup berat dihitung: tanpa polling otomatis. */
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Posisi Keuangan Konveksi';

    protected ?string $description = 'Neraca per hari ini. Klik untuk rincian & neraca tiap toko.';

    /** Maks. 4 kolom agar nominal rupiah tidak terpotong. */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4];

    protected function getStats(): array
    {
        $sheet = app(BalanceSheetService::class)->konveksiSheet(today());
        $url = BalanceSheet::getUrl();
        $rupiah = fn (float $amount): string => Number::currency($amount, 'IDR', 'id', 0);

        $cash = BalanceSheetService::sumRoles($sheet['assets'], ['cash']);
        $receivable = BalanceSheetService::sumRoles($sheet['assets'], ['ar_reseller']);
        $inventory = BalanceSheetService::sumRoles($sheet['assets'], ['inventory_fg', 'inventory_material', 'wip']);
        $liabilities = BalanceSheetService::total($sheet['liabilities']);
        $equity = BalanceSheetService::total($sheet['equity']);

        return [
            Stat::make('Kas & Bank', $rupiah($cash))
                ->description('Semua rekening (buku besar)')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color($cash >= 0 ? 'success' : 'danger')
                ->url($url),
            Stat::make('Piutang Reseller', $rupiah($receivable))
                ->description('Belum dibayar reseller')
                ->descriptionIcon(Heroicon::OutlinedReceiptPercent)
                ->color('warning')
                ->url($url),
            Stat::make('Persediaan', $rupiah($inventory))
                ->description('Barang jadi, bahan & SPK berjalan')
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('info')
                ->url($url),
            Stat::make('Kewajiban', $rupiah($liabilities))
                ->description('Utang upah, supplier & deposit')
                ->descriptionIcon(Heroicon::OutlinedCreditCard)
                ->color($liabilities > 0 ? 'danger' : 'gray')
                ->url($url),
            Stat::make('Ekuitas', $rupiah($equity))
                ->description('Laba tahun berjalan '.$rupiah($sheet['ytd_profit']))
                ->descriptionIcon(Heroicon::OutlinedScale)
                ->color($equity >= 0 ? 'primary' : 'danger')
                ->url($url),
        ];
    }
}
