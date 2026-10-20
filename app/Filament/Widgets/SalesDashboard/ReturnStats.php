<?php

namespace App\Filament\Widgets\SalesDashboard;

use App\Services\SalesDashboardService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * E. Analisa retur: ringkasan retur dalam periode (berdasarkan tanggal retur) + pembanding periode sebelumnya.
 */
class ReturnStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 20;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'G. Analisa Produk Retur';

    protected ?string $description = 'Berdasarkan tanggal retur dalam periode; % retur = retur ÷ terjual dalam periode.';

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $d = app(SalesDashboardService::class)->build($this->pageFilters ?? []);
        $r = $d['returns'];
        $prev = $d['previous'];

        return [
            SummaryStats::compare(Stat::make('Total retur', Number::format($r['qty'], locale: 'id').' pcs'), $r['qty'], $prev['returned'], higherIsBetter: false),
            SummaryStats::compare(
                Stat::make('Tingkat retur', $r['rate'] === null ? '-' : Number::format($r['rate'] * 100, 1, locale: 'id').'%'),
                ($r['rate'] ?? 0) * 100, ($prev['rate'] ?? 0) * 100, higherIsBetter: false,
            ),
            Stat::make('Cacat produksi', Number::format($r['production_defects'], locale: 'id').' pcs')
                ->description('Alasan cacat jahitan / cacat bahan')
                ->descriptionIcon(Heroicon::OutlinedScissors)
                ->color($r['production_defects'] > 0 ? 'danger' : 'success'),
            Stat::make('Nilai retur', Number::currency($r['value'], 'IDR', 'id', 0))
                ->description(Number::format($r['sku_count'], locale: 'id').' SKU diretur')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('warning'),
            Stat::make('Kembali ke stok', Number::format($r['restocked'], locale: 'id').' pcs')
                ->description(Number::format(max($r['qty'] - $r['restocked'], 0), locale: 'id').' pcs tidak bisa dijual lagi')
                ->descriptionIcon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->color('info'),
        ];
    }
}
