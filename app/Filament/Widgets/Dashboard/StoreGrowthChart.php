<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Tren penjualan bulanan 5 toko teratas pada rentang terpilih.
 */
class StoreGrowthChart extends DashboardChart
{
    protected static ?int $sort = 9;

    protected ?string $heading = 'Pertumbuhan Toko Teratas';

    protected ?string $description = 'Tagihan per bulan dari 5 toko dengan penjualan terbesar pada rentang ini.';

    public ?string $filter = '6';

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
        $series = $this->analytics()->monthlyByDimension('store', $months, 5, withOthers: false);

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
        return $this->chartOptions();
    }
}
