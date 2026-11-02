<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Arus kas tercatat per bulan: pembayaran reseller (masuk) vs biaya, upah, pembelian & pembayaran supplier (keluar).
 */
class CashFlowChart extends DashboardChart
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Arus Kas';

    protected ?string $description = 'Kas masuk (pembayaran reseller, setoran modal) vs kas keluar (biaya, upah, pembelian bahan & barang jadi, bayar supplier, prive).';

    public ?string $filter = '6';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return DashboardAnalytics::MONTH_RANGES;
    }

    protected function getData(): array
    {
        $months = $this->analytics()->months(DashboardAnalytics::monthCount($this->filter));
        $cash = $this->analytics()->cashFlowPerMonth($months);
        $net = array_map(fn (float $in, float $out): float => round($in - $out, 2), $cash['in'], $cash['out']);

        return [
            'datasets' => [
                $this->dataset('Kas masuk', $cash['in'], 0),
                $this->dataset('Kas keluar', $cash['out'], 3),
                $this->dataset('Selisih', $net, 1, ['type' => 'line', 'backgroundColor' => static::color(1)]),
            ],
            'labels' => $this->analytics()->monthLabels($months),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->chartOptions();
    }
}
