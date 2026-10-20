<?php

namespace App\Filament\Widgets\SalesDashboard;

use App\Services\SalesDashboardService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * KPI periode + pembanding periode sebelumnya (jumlah hari sama, tepat sebelum periode).
 */
class SummaryStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3, 'xl' => 6];
    }

    protected function getStats(): array
    {
        $d = app(SalesDashboardService::class)->build($this->pageFilters ?? []);
        $kpi = $d['kpi'];
        $prev = $d['previous'];
        $profit = $kpi['bill'] - $kpi['hpp'];

        return [
            self::compare(Stat::make('Terjual dalam periode', Number::format($kpi['sold'], locale: 'id').' pcs'), $kpi['sold'], $prev['sold']),
            self::compare(Stat::make('Omzet konveksi (tagihan)', Number::currency($kpi['bill'], 'IDR', 'id', 0)), $kpi['bill'], $prev['bill']),
            Stat::make('Laba kotor konveksi', Number::currency($profit, 'IDR', 'id', 0))
                ->description('Margin '.($kpi['bill'] > 0 ? Number::format($profit / $kpi['bill'] * 100, 1, locale: 'id') : '0').'% dari tagihan')
                ->descriptionIcon(Heroicon::OutlinedPresentationChartLine)
                ->color($profit >= 0 ? 'success' : 'danger'),
            Stat::make('SKU laku tapi stok HABIS', Number::format($kpi['out_of_stock'], locale: 'id'))
                ->description('Terjual dalam periode, stok gudang ≤ 0')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
            Stat::make('SKU harus SEGERA diproduksi', Number::format($kpi['urgent'], locale: 'id'))
                ->description(Number::format($kpi['in_production'], locale: 'id').' pcs sedang diproduksi (SPK)')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning'),
            Stat::make('Total saran produksi', Number::format($kpi['suggested'], locale: 'id').' pcs')
                ->description(Number::format($kpi['priority_count'], locale: 'id').' SKU perlu diproduksi')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('info'),
        ];
    }

    /**
     * Deskripsi naik/turun dibanding periode sebelumnya.
     */
    public static function compare(Stat $stat, float $current, float $previous, bool $higherIsBetter = true): Stat
    {
        if ($previous == 0) {
            return $stat->description($current > 0 ? 'Periode sebelumnya: 0' : 'Belum ada data')
                ->descriptionIcon(Heroicon::OutlinedMinus)
                ->color('gray');
        }

        $change = ($current - $previous) / abs($previous) * 100;
        $up = $change >= 0;

        return $stat
            ->description(($up ? '+' : '').Number::format($change, 1, locale: 'id').'% vs periode sebelumnya')
            ->descriptionIcon($up ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown)
            ->color($up === $higherIsBetter ? 'success' : 'danger');
    }
}
