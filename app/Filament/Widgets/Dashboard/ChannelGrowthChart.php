<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Penjualan per marketplace / channel per bulan (batang bertumpuk).
 */
class ChannelGrowthChart extends DashboardChart
{
    protected static ?int $sort = 7;

    protected ?string $heading = 'Pertumbuhan per Marketplace';

    protected ?string $description = 'Tagihan ke reseller per channel per bulan.';

    public ?string $filter = '12';

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
        $series = $this->analytics()->monthlyByDimension('channel', $months, 7);

        return [
            'datasets' => array_map(
                fn (string $name, array $data, int $index): array => $this->dataset($name, $data, $index),
                array_keys($series), $series, array_keys(array_keys($series)),
            ),
            'labels' => $this->analytics()->monthLabels($months),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->chartOptions(stacked: true);
    }
}
