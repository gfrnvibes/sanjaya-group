<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Dasar chart dashboard utama: palet warna tetap, tinggi ringkas, sumbu & tooltip rupiah singkat (rb/jt/M).
 */
abstract class DashboardChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $maxHeight = '260px';

    /** Palet berurutan untuk dataset (emerald, sky, amber, rose, violet, teal, orange, slate). */
    protected const PALETTE = ['#10b981', '#0ea5e9', '#f59e0b', '#f43f5e', '#8b5cf6', '#14b8a6', '#f97316', '#94a3b8'];

    /** Nilai sumbu Y: rupiah (true) atau jumlah biasa (false). */
    protected bool $money = true;

    protected function analytics(): DashboardAnalytics
    {
        return app(DashboardAnalytics::class);
    }

    protected static function color(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * Dataset garis/batang dengan warna palet.
     *
     * @param  list<float|int>  $data
     * @return array<string, mixed>
     */
    protected function dataset(string $label, array $data, int $index, array $extra = []): array
    {
        $color = static::color($index);

        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $this->getType() === 'line' ? $color.'33' : $color,
            'borderWidth' => 2,
            'pointRadius' => 2,
            'tension' => 0.3,
            ...$extra,
        ];
    }

    /**
     * Opsi umum: legenda bawah, angka singkat format Indonesia. $horizontal = batang mendatar (nilai di sumbu X).
     */
    protected function chartOptions(bool $stacked = false, bool $horizontal = false): RawJs
    {
        $valueAxis = $horizontal ? 'x' : 'y';
        $categoryAxis = $horizontal ? 'y' : 'x';
        $prefix = $this->money ? "'Rp '" : "''";
        $stack = $stacked ? 'true' : 'false';
        $indexAxis = $horizontal ? "indexAxis: 'y'," : '';

        return RawJs::make(<<<JS
            {
                {$indexAxis}
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ' ' + ctx.dataset.label + ': ' + {$prefix} + new Intl.NumberFormat('id-ID').format(ctx.parsed.{$valueAxis} ?? ctx.parsed),
                        },
                    },
                },
                scales: {
                    {$valueAxis}: {
                        stacked: {$stack},
                        beginAtZero: true,
                        ticks: { font: { size: 10 }, callback: (value) => {$prefix} + new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(value) },
                    },
                    {$categoryAxis}: { stacked: {$stack}, ticks: { font: { size: 10 } } },
                },
            }
            JS);
    }
}
