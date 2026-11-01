<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\DashboardAnalytics;
use Filament\Support\RawJs;

/**
 * Batang mendatar: item teratas periode terpilih vs periode sebelumnya (panjang sama), dengan % pertumbuhan di label.
 */
abstract class PeriodComparisonChart extends DashboardChart
{
    public ?string $filter = 'month';

    protected int $top = 10;

    /** Dimensi DashboardAnalytics: store, reseller, channel, model. */
    abstract protected function dimension(): string;

    /** amount (tagihan Rp) atau qty (pcs). */
    protected function measure(): string
    {
        return 'amount';
    }

    public function getDescription(): ?string
    {
        $period = $this->analytics()->period($this->filter);

        return "{$period['label']} vs {$period['previous_label']}.";
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return DashboardAnalytics::PERIODS;
    }

    protected function getData(): array
    {
        $rows = $this->analytics()->compareByDimension($this->dimension(), $this->analytics()->period($this->filter), $this->top, $this->measure());

        $growth = fn (array $row): string => $row['previous'] > 0
            ? sprintf('%+.0f%%', ($row['current'] - $row['previous']) / $row['previous'] * 100)
            : 'baru';

        return [
            'datasets' => [
                $this->dataset('Periode ini', array_column($rows, 'current'), 0),
                $this->dataset('Periode sebelumnya', array_column($rows, 'previous'), 7),
            ],
            'labels' => array_map(fn (array $row): string => $row['name'].' ('.$growth($row).')', $rows),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->chartOptions(horizontal: true);
    }
}
