<?php

namespace App\Filament\Widgets\Dashboard;

/**
 * 10 model produk terlaris (pcs): periode ini vs periode sebelumnya.
 */
class ProductGrowthChart extends PeriodComparisonChart
{
    protected static ?int $sort = 11;

    protected ?string $heading = 'Pertumbuhan Produk Terlaris (pcs)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected bool $money = false;

    protected function dimension(): string
    {
        return 'model';
    }

    protected function measure(): string
    {
        return 'qty';
    }
}
