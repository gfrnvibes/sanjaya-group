<?php

namespace App\Filament\Widgets\Dashboard;

/**
 * 10 reseller teratas: tagihan periode ini vs periode sebelumnya.
 */
class ResellerGrowthChart extends PeriodComparisonChart
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Pertumbuhan Reseller';

    protected ?string $maxHeight = '300px';

    protected function dimension(): string
    {
        return 'reseller';
    }
}
