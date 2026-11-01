<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Tren penjualan bersih, laba kotor, biaya & laba bersih konveksi per bulan.
 */
class SalesTrendChart extends DashboardChart
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Pertumbuhan Penjualan & Laba Konveksi';

    protected ?string $description = 'Penjualan bersih ke reseller (setelah retur), laba kotor, biaya operasional, dan laba bersih per bulan.';

    public ?string $filter = '12';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return DashboardAnalytics::MONTH_RANGES;
    }

    protected function getData(): array
    {
        $months = $this->analytics()->months(DashboardAnalytics::monthCount($this->filter));
        $profit = $this->analytics()->profitPerMonth($months);

        return [
            'datasets' => [
                $this->dataset('Penjualan bersih', $profit['net_sales'], 1, ['fill' => true]),
                $this->dataset('Laba kotor', $profit['gross_profit'], 0),
                $this->dataset('Biaya operasional', $profit['expenses'], 3, ['borderDash' => [4, 4]]),
                $this->dataset('Laba bersih', $profit['net_profit'], 4),
            ],
            'labels' => $this->analytics()->monthLabels($months),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->chartOptions();
    }
}
