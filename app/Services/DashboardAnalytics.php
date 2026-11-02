<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data chart dashboard utama: deret bulanan & perbandingan periode dari penjualan (v_sales_lines),
 * laba rugi bulanan, dan arus kas tercatat. Penjualan = tagihan ke reseller (selain pesanan batal & retur).
 */
class DashboardAnalytics
{
    /** Pilihan rentang chart bulanan. */
    public const MONTH_RANGES = [
        '6' => '6 bulan',
        '12' => '12 bulan',
        '24' => '24 bulan',
    ];

    /** Pilihan periode chart perbandingan (vs periode sebelumnya dengan panjang sama). */
    public const PERIODS = [
        'month' => 'Bulan ini',
        'quarter' => '3 bulan terakhir',
        'half' => '6 bulan terakhir',
        'year' => 'Tahun ini',
    ];

    /** Dimensi penjualan yang bisa dikelompokkan: kolom kunci & sumber nama. */
    private const DIMENSIONS = [
        'store' => ['key' => 'l.store_id', 'join' => 'JOIN stores d ON d.id = l.store_id', 'name' => 'd.name'],
        'reseller' => ['key' => 'l.reseller_id', 'join' => 'JOIN resellers d ON d.id = l.reseller_id', 'name' => 'd.name'],
        'channel' => ['key' => 'l.channel_id', 'join' => 'JOIN channels d ON d.id = l.channel_id', 'name' => 'd.name'],
        'model' => ['key' => 'v.model_id', 'join' => 'JOIN product_variants v ON v.id = l.variant_id JOIN product_models d ON d.id = v.model_id', 'name' => 'd.name'],
    ];

    private const SOLD = "l.status NOT IN ('cancelled', 'returned')";

    /**
     * Awal bulan untuk N bulan terakhir (termasuk bulan ini), urut lama → baru.
     *
     * @return list<Carbon>
     */
    public function months(int $count): array
    {
        $start = today()->startOfMonth()->subMonths(max($count, 1) - 1);

        return array_map(fn (int $i): Carbon => $start->copy()->addMonths($i), range(0, max($count, 1) - 1));
    }

    /**
     * @param  list<Carbon>  $months
     * @return list<string>
     */
    public function monthLabels(array $months): array
    {
        return array_map(fn (Carbon $month): string => $month->locale('id')->translatedFormat('M y'), $months);
    }

    public static function monthCount(?string $filter): int
    {
        return array_key_exists((string) $filter, self::MONTH_RANGES) ? (int) $filter : 12;
    }

    /**
     * Rentang periode terpilih dan periode sebelumnya dengan panjang sama.
     *
     * @return array{from: Carbon, until: Carbon, previous_from: Carbon, previous_until: Carbon, label: string, previous_label: string}
     */
    public function period(?string $filter): array
    {
        $until = today();
        $months = match ($filter) {
            'quarter' => 3,
            'half' => 6,
            'year' => $until->month,
            default => 1,
        };
        $from = $until->copy()->startOfMonth()->subMonths($months - 1);

        // Pembanding: rentang yang sama mundur sejumlah bulan periode (mis. 1–15 bulan ini vs 1–15 bulan lalu).
        $previousFrom = $from->copy()->subMonths($months);
        $previousUntil = $until->copy()->subMonthsNoOverflow($months);

        $format = fn (Carbon $a, Carbon $b): string => $a->locale('id')->translatedFormat('d M').' – '.$b->locale('id')->translatedFormat('d M Y');

        return [
            'from' => $from,
            'until' => $until,
            'previous_from' => $previousFrom,
            'previous_until' => $previousUntil,
            'label' => $format($from, $until),
            'previous_label' => $format($previousFrom, $previousUntil),
        ];
    }

    /**
     * Tagihan per bulan per item dimensi teratas (berdasar total rentang), sisanya digabung "Lainnya".
     *
     * @param  list<Carbon>  $months
     * @return array<string, list<float>> nama => nilai per bulan
     */
    public function monthlyByDimension(string $dimension, array $months, int $top, bool $withOthers = true): array
    {
        $dim = self::DIMENSIONS[$dimension];
        $from = $months[0]->toDateString();
        $until = end($months)->copy()->endOfMonth()->toDateString();

        $rows = collect(DB::select(<<<SQL
            SELECT {$dim['key']} AS id, {$dim['name']} AS name, date_trunc('month', l.order_date)::date AS month, SUM(l.bill_amount) AS amount
            FROM v_sales_lines l {$dim['join']}
            WHERE {$this->sold()} AND l.order_date BETWEEN ? AND ?
            GROUP BY 1, 2, 3
            SQL, [$from, $until]));

        $ranked = $rows->groupBy('id')->sortByDesc(fn (Collection $group): float => $group->sum(fn ($row): float => (float) $row->amount));
        $series = [];

        foreach ($ranked->take($top) as $group) {
            $series[$group->first()->name] = $this->spread($group, $months);
        }

        if ($withOthers && $ranked->count() > $top) {
            $series['Lainnya'] = $this->spread($ranked->slice($top)->flatten(1), $months);
        }

        return $series;
    }

    /**
     * Item dimensi teratas pada periode terpilih beserta nilainya pada periode sebelumnya.
     *
     * @return list<array{name: string, current: float, previous: float}>
     */
    public function compareByDimension(string $dimension, array $period, int $top, string $measure = 'amount'): array
    {
        $dim = self::DIMENSIONS[$dimension];
        $value = $measure === 'qty' ? 'l.qty' : 'l.bill_amount';

        $rows = DB::select(<<<SQL
            SELECT {$dim['name']} AS name,
                   COALESCE(SUM({$value}) FILTER (WHERE l.order_date BETWEEN ? AND ?), 0) AS current,
                   COALESCE(SUM({$value}) FILTER (WHERE l.order_date BETWEEN ? AND ?), 0) AS previous
            FROM v_sales_lines l {$dim['join']}
            WHERE {$this->sold()} AND l.order_date BETWEEN ? AND ?
            GROUP BY {$dim['key']}, {$dim['name']}
            HAVING COALESCE(SUM({$value}) FILTER (WHERE l.order_date BETWEEN ? AND ?), 0) > 0
            ORDER BY current DESC
            LIMIT {$top}
            SQL, [
            $period['from']->toDateString(), $period['until']->toDateString(),
            $period['previous_from']->toDateString(), $period['previous_until']->toDateString(),
            $period['previous_from']->toDateString(), $period['until']->toDateString(),
            $period['from']->toDateString(), $period['until']->toDateString(),
        ]);

        return array_map(fn ($row): array => [
            'name' => (string) $row->name,
            'current' => round((float) $row->current, 2),
            'previous' => round((float) $row->previous, 2),
        ], $rows);
    }

    /**
     * Jumlah toko, reseller & SKU yang punya penjualan tiap bulan.
     *
     * @param  list<Carbon>  $months
     * @return array{stores: list<int>, resellers: list<int>, variants: list<int>, new_stores: list<int>}
     */
    public function activePerMonth(array $months): array
    {
        $from = $months[0]->toDateString();
        $until = end($months)->copy()->endOfMonth()->toDateString();

        $rows = collect(DB::select(<<<SQL
            SELECT date_trunc('month', l.order_date)::date AS month,
                   COUNT(DISTINCT l.store_id) AS stores, COUNT(DISTINCT l.reseller_id) AS resellers, COUNT(DISTINCT l.variant_id) AS variants
            FROM v_sales_lines l
            WHERE {$this->sold()} AND l.order_date BETWEEN ? AND ?
            GROUP BY 1
            SQL, [$from, $until]))->keyBy(fn ($row): string => Carbon::parse($row->month)->format('Y-m'));

        // toko baru = bulan penjualan pertama toko tersebut
        $newStores = collect(DB::select(<<<SQL
            SELECT date_trunc('month', first_sale)::date AS month, COUNT(*) AS total
            FROM (SELECT l.store_id, MIN(l.order_date) AS first_sale FROM v_sales_lines l WHERE {$this->sold()} GROUP BY 1) f
            WHERE first_sale BETWEEN ? AND ?
            GROUP BY 1
            SQL, [$from, $until]))->keyBy(fn ($row): string => Carbon::parse($row->month)->format('Y-m'));

        $pick = fn (Collection $rows, string $column): array => array_map(
            fn (Carbon $month): int => (int) ($rows[$month->format('Y-m')]->{$column} ?? 0),
            $months,
        );

        return [
            'stores' => $pick($rows, 'stores'),
            'resellers' => $pick($rows, 'resellers'),
            'variants' => $pick($rows, 'variants'),
            'new_stores' => $pick($newStores, 'total'),
        ];
    }

    /**
     * Laba rugi konveksi per bulan (view v_konveksi_monthly_pl).
     *
     * @param  list<Carbon>  $months
     * @return array{net_sales: list<float>, gross_profit: list<float>, expenses: list<float>, net_profit: list<float>}
     */
    public function profitPerMonth(array $months): array
    {
        $rows = collect(DB::select(
            'SELECT month, net_sales, gross_profit, expenses, net_profit FROM v_konveksi_monthly_pl WHERE month BETWEEN ? AND ?',
            [$months[0]->toDateString(), end($months)->toDateString()],
        ))->keyBy(fn ($row): string => Carbon::parse($row->month)->format('Y-m'));

        $pick = fn (string $column): array => array_map(
            fn (Carbon $month): float => round((float) ($rows[$month->format('Y-m')]->{$column} ?? 0), 2),
            $months,
        );

        return [
            'net_sales' => $pick('net_sales'),
            'gross_profit' => $pick('gross_profit'),
            'expenses' => $pick('expenses'),
            'net_profit' => $pick('net_profit'),
        ];
    }

    /**
     * Kas masuk (pembayaran reseller) & kas keluar tercatat per bulan, sama dengan dasar kas di Neraca.
     *
     * @param  list<Carbon>  $months
     * @return array{in: list<float>, out: list<float>}
     */
    public function cashFlowPerMonth(array $months): array
    {
        return app(CashLedger::class)->monthly($months);
    }

    /**
     * @param  Collection<int, object>  $rows  baris {month, amount}
     * @param  list<Carbon>  $months
     * @return list<float>
     */
    private function spread(Collection $rows, array $months): array
    {
        $totals = $rows->groupBy(fn ($row): string => Carbon::parse($row->month)->format('Y-m'))
            ->map(fn (Collection $group): float => round($group->sum(fn ($row): float => (float) $row->amount), 2));

        return array_map(fn (Carbon $month): float => (float) ($totals[$month->format('Y-m')] ?? 0), $months);
    }

    private function sold(): string
    {
        return self::SOLD;
    }
}
