<?php

namespace App\Services;

use App\Filament\Pages\Reports\FinancialReportPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAPORAN TAHUNAN konveksi: ringkasan KPI vs tahun lalu, laba rugi 12 bulan, laporan keuangan tahunan
 * (laba rugi, neraca akhir tahun, arus kas, perubahan modal), analisis penjualan & rasio.
 */
class AnnualReport
{
    public function __construct(
        private FinancialStatements $statements,
        private BalanceSheetService $balanceSheets,
        private DashboardAnalytics $analytics,
    ) {}

    /**
     * Tahun yang punya transaksi (terbaru dulu).
     *
     * @return array<int, string>
     */
    public static function years(): array
    {
        $first = (int) (DB::scalar('SELECT EXTRACT(YEAR FROM MIN(order_date)) FROM sales_orders') ?: today()->year);

        return collect(range(today()->year, min($first, today()->year)))->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $year): array
    {
        $from = Carbon::create($year)->startOfYear();
        $until = $from->copy()->endOfYear()->min(today())->startOfDay();
        $previous = [$from->copy()->subYear(), $until->copy()->subYearNoOverflow()];
        $k = FinancialStatements::KONVEKSI;

        $income = $this->statements->incomeStatement($k, $from, $until);
        $incomePrev = $this->statements->incomeStatement($k, ...$previous);
        $sheet = $this->balanceSheets->konveksiSheet($until);
        $sheetPrev = $this->balanceSheets->konveksiSheet($from->copy()->subDay());
        $cash = $this->statements->cashFlow($k, $from, $until);
        $cashPrev = $this->statements->cashFlow($k, ...$previous);
        $equity = $this->statements->equityStatement($k, $from, $until);
        $equityPrev = $this->statements->equityStatement($k, ...$previous);

        $inventory = fn (array $sheet): float => BalanceSheetService::sumRoles($sheet['assets'], ['inventory_fg', 'inventory_material', 'wip']);
        $amount = fn (array $rows, string $key): float => (float) (collect($rows)->firstWhere('key', $key)['amount'] ?? 0);

        $days = (int) $from->diffInDays($until) + 1;
        $hpp = -$amount($income['rows'], 'hpp');
        $avgInventory = ($inventory($sheet) + $inventory($sheetPrev)) / 2;
        $receivable = BalanceSheetService::sumRoles($sheet['assets'], ['ar_reseller']);

        $label = fn (Carbon $a, Carbon $b): string => $a->locale('id')->translatedFormat('d M Y').' – '.$b->locale('id')->translatedFormat('d M Y');
        $columns = [$label($from, $until), $label(...$previous)];
        $report = fn (string $title, string $sheet, array $current, array $prev, ?array $own = null): array => [
            'title' => $title, 'sheet' => $sheet, 'entity' => 'Konveksi', 'columns' => $own ?? $columns,
            'rows' => FinancialReportPage::merge([$current, $prev]), 'notes' => [],
        ];

        return [
            'year' => $year,
            'from' => $from,
            'until' => $until,
            'kpis' => [
                ['label' => 'Penjualan bersih', 'value' => $income['net_sales'], 'previous' => $incomePrev['net_sales']],
                ['label' => 'Laba kotor', 'value' => $amount($income['rows'], 'gross_profit'), 'previous' => $amount($incomePrev['rows'], 'gross_profit')],
                ['label' => 'Laba bersih', 'value' => $income['net_profit'], 'previous' => $incomePrev['net_profit']],
                ['label' => 'Kas akhir tahun', 'value' => $cash['closing'], 'previous' => $cashPrev['closing']],
                ['label' => 'Piutang reseller', 'value' => $receivable, 'previous' => BalanceSheetService::sumRoles($sheetPrev['assets'], ['ar_reseller'])],
                ['label' => 'Persediaan', 'value' => $inventory($sheet), 'previous' => $inventory($sheetPrev)],
            ],
            'monthly' => $this->monthly($from, $until),
            'reports' => [
                $report('Laporan Laba Rugi', 'Laba Rugi', $income['rows'], $incomePrev['rows']),
                $report('Neraca', 'Neraca', FinancialStatements::balanceRows($sheet), FinancialStatements::balanceRows($sheetPrev),
                    ['Per '.$until->locale('id')->translatedFormat('d M Y'), 'Per '.$from->copy()->subDay()->locale('id')->translatedFormat('d M Y')]),
                $report('Laporan Arus Kas', 'Arus Kas', $cash['rows'], $cashPrev['rows']),
                $report('Laporan Perubahan Modal', 'Perubahan Modal', $equity['rows'], $equityPrev['rows']),
            ],
            'ratios' => [
                ['label' => 'Margin laba kotor', 'value' => $income['net_sales'] ? $amount($income['rows'], 'gross_profit') / $income['net_sales'] : null, 'format' => 'percent', 'hint' => 'Laba kotor / penjualan bersih'],
                ['label' => 'Margin laba bersih', 'value' => $income['net_sales'] ? $income['net_profit'] / $income['net_sales'] : null, 'format' => 'percent', 'hint' => 'Laba bersih / penjualan bersih'],
                ['label' => 'Perputaran persediaan', 'value' => $avgInventory > 0 ? $hpp / $avgInventory : null, 'format' => 'times', 'hint' => 'HPP / rata-rata persediaan awal & akhir tahun'],
                ['label' => 'Rata-rata umur persediaan', 'value' => $hpp > 0 ? $avgInventory / $hpp * $days : null, 'format' => 'days', 'hint' => 'Rata-rata persediaan / HPP x hari'],
                ['label' => 'Rata-rata umur piutang', 'value' => $income['net_sales'] > 0 ? $receivable / $income['net_sales'] * $days : null, 'format' => 'days', 'hint' => 'Piutang akhir / penjualan bersih x hari'],
            ],
            'analysis' => $this->analysis($from, $until, ...$previous),
        ];
    }

    /**
     * Laba rugi per bulan (kolom = bulan).
     *
     * @return array{months: list<string>, rows: list<array{label: string, values: list<float>, total: float, strong: bool}>}
     */
    private function monthly(Carbon $from, Carbon $until): array
    {
        $months = [];
        $results = [];

        for ($month = $from->copy(); $month->lte($until); $month->addMonth()) {
            $months[] = $month->locale('id')->translatedFormat('M');
            $results[] = $this->statements->incomeStatement(FinancialStatements::KONVEKSI, $month->copy(), $month->copy()->endOfMonth()->min($until))['rows'];
        }

        $rows = [
            'net_sales' => ['Penjualan bersih', false],
            'hpp' => ['HPP', false],
            'gross_profit' => ['Laba kotor', true],
            'total_opex' => ['Beban operasional', false],
            'profit_before_tax' => ['Laba sebelum pajak', false],
            'tax' => ['Pajak', false],
            'net_profit' => ['Laba bersih', true],
        ];

        if (! FinancialStatements::taxEnabled()) {
            unset($rows['profit_before_tax'], $rows['tax']);
        }

        return [
            'months' => $months,
            'rows' => collect($rows)->map(function (array $meta, string $key) use ($results): array {
                $values = array_map(fn (array $rows): float => (float) (collect($rows)->firstWhere('key', $key)['amount'] ?? 0), $results);

                return ['label' => $meta[0], 'values' => $values, 'total' => round(array_sum($values), 2), 'strong' => $meta[1]];
            })->values()->all(),
        ];
    }

    /**
     * Penjualan per channel, toko, reseller & produk: tahun ini vs tahun lalu.
     *
     * @return array<string, list<array{name: string, current: float, previous: float}>>
     */
    private function analysis(Carbon $from, Carbon $until, Carbon $previousFrom, Carbon $previousUntil): array
    {
        $period = ['from' => $from, 'until' => $until, 'previous_from' => $previousFrom, 'previous_until' => $previousUntil];

        return [
            'Marketplace / channel (Rp)' => $this->analytics->compareByDimension('channel', $period, 10),
            'Toko teratas (Rp)' => $this->analytics->compareByDimension('store', $period, 10),
            'Reseller teratas (Rp)' => $this->analytics->compareByDimension('reseller', $period, 10),
            'Produk terlaris (pcs)' => $this->analytics->compareByDimension('model', $period, 10, 'qty'),
        ];
    }
}
