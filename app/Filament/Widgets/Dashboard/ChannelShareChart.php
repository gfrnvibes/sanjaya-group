<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Porsi penjualan tiap marketplace / channel pada periode terpilih.
 */
class ChannelShareChart extends DashboardChart
{
    protected static ?int $sort = 8;

    protected ?string $heading = 'Porsi Penjualan per Marketplace';

    public ?string $filter = 'month';

    public function getDescription(): ?string
    {
        $period = $this->analytics()->period($this->filter);

        return 'Tagihan ke reseller '.$period['label'].'.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getFilters(): ?array
    {
        return DashboardAnalytics::PERIODS;
    }

    protected function getData(): array
    {
        $rows = $this->analytics()->compareByDimension('channel', $this->analytics()->period($this->filter), 10);

        return [
            'datasets' => [[
                'label' => 'Tagihan',
                'data' => array_column($rows, 'current'),
                'backgroundColor' => array_map(fn (int $index): string => static::color($index), array_keys($rows)),
                'borderWidth' => 1,
            ]],
            'labels' => array_column($rows, 'name'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((sum, value) => sum + value, 0);
                                const pct = total ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                return ' ' + ctx.label + ': Rp ' + new Intl.NumberFormat('id-ID').format(ctx.parsed) + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                scales: { x: { display: false }, y: { display: false } },
            }
            JS);
    }
}
