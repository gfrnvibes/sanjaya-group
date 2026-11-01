<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Jumlah toko, reseller & SKU yang aktif berjualan per bulan, plus toko baru (bulan penjualan pertama).
 */
class ActivePartnersChart extends DashboardChart
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Pertumbuhan Toko & Reseller Aktif';

    protected ?string $description = 'Jumlah yang memiliki penjualan pada bulan tersebut.';

    public ?string $filter = '12';

    protected bool $money = false;

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
        $active = $this->analytics()->activePerMonth($months);

        return [
            'datasets' => [
                $this->dataset('Toko aktif', $active['stores'], 0),
                $this->dataset('Reseller aktif', $active['resellers'], 1),
                $this->dataset('Toko baru', $active['new_stores'], 2, ['type' => 'bar', 'backgroundColor' => static::color(2)]),
                $this->dataset('SKU terjual', $active['variants'], 4, ['borderDash' => [4, 4], 'hidden' => true]),
            ],
            'labels' => $this->analytics()->monthLabels($months),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->chartOptions();
    }
}
