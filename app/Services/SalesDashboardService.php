<?php

namespace App\Services;

use App\Enums\BomLineType;
use App\Enums\ReturnReason;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DASHBOARD PRODUK TERLARIS, PRIORITAS PRODUKSI, MARGIN, STOK LAMBAT LAKU & RETUR.
 *
 * Rumus prioritas (sama dengan sheet dashboard):
 *  - terjual/hari  = terjual dalam periode / jumlah hari periode
 *  - stok tersedia = stok gudang + qty SPK yang sedang berjalan (dipotong/dijahit, belum selesai)
 *  - hari stok     = stok tersedia / terjual per hari
 *  - saran         = terjual/hari x (target stok + lead time) - stok tersedia, dibulatkan ke atas ke kelipatan
 *  - prioritas     = 1 HABIS (stok tersedia <= 0) > 2 SEGERA (habis sebelum produksi selesai)
 *                    > 3 PERLU (< target + lead time) > AMAN. Hanya model berjenis produksi; lainnya "-".
 * Filter toko / channel / reseller membatasi penjualan & retur; filter kategori membatasi produk.
 */
class SalesDashboardService
{
    private array $computed = [];

    public function forget(): void
    {
        $this->computed = [];
    }

    public const PRIORITY_ORDER = ['1 HABIS' => 1, '2 SEGERA' => 2, '3 PERLU' => 3, 'AMAN' => 4, '-' => 5];

    public function __construct(private CostingService $costing) {}

    /**
     * Pengaturan default (dari menu Pengaturan) + tanggal acuan = tanggal penjualan terakhir.
     *
     * @return array{days: int, target_days: int, lead_days: int, batch: int, ref_date: string, dead_days: int}
     */
    public function defaults(): array
    {
        $lastSale = DB::scalar("SELECT MAX(order_date) FROM sales_orders WHERE status <> 'cancelled' AND order_date <= current_date");

        return [
            'days' => (int) Setting::valueOf('analysis_days', 30),
            'target_days' => (int) Setting::valueOf('target_stock_days', 30),
            'lead_days' => (int) Setting::valueOf('lead_time_days', 7),
            'batch' => max(1, (int) Setting::valueOf('production_batch', 1)),
            'ref_date' => $lastSale ? Carbon::parse($lastSale)->toDateString() : today()->toDateString(),
            'dead_days' => (int) Setting::valueOf('dead_stock_days', 60),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(array $params): array
    {
        // Banyak widget memakai hasil yang sama dalam 1 request: hitung sekali per kombinasi pengaturan.
        return $this->computed['dashboard:'.$this->key($params)] ??= $this->compute($params);
    }

    /**
     * Kebutuhan bahan untuk seluruh saran produksi (resep x saran) dibanding stok bahan.
     *
     * @param  array<string, mixed>  $params
     * @return array{rows: Collection<int, array<string, mixed>>, missing: list<string>}
     */
    public function materialNeeds(array $params): array
    {
        return $this->computed['materials:'.$this->key($params)] ??= $this->computeMaterialNeeds($this->build($params));
    }

    // ------------------------------------------------------------------ perhitungan

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function compute(array $params): array
    {
        $defaults = $this->defaults();
        $days = max(1, (int) ($params['days'] ?? $defaults['days']));
        $targetDays = max(0, (int) ($params['target_days'] ?? $defaults['target_days']));
        $leadDays = max(0, (int) ($params['lead_days'] ?? $defaults['lead_days']));
        $batch = max(1, (int) ($params['batch'] ?? $defaults['batch']));
        $deadDays = max(1, (int) ($params['dead_days'] ?? $defaults['dead_days']));
        $end = Carbon::parse(($params['ref_date'] ?? null) ?: $defaults['ref_date'])->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        $filters = $this->filters($params);

        $variants = $this->variants($start->toDateString(), $end->toDateString(), $filters)
            ->map(fn (array $row): array => $this->withPriority($row, $days, $targetDays, $leadDays, $batch));

        $priority = $variants
            ->whereIn('priority', ['1 HABIS', '2 SEGERA', '3 PERLU'])
            ->sortBy([['priority_rank', 'asc'], ['sold', 'desc']])
            ->values();

        $previous = $this->totals($start->copy()->subDays($days)->toDateString(), $start->copy()->subDay()->toDateString(), $filters);
        $returns = $this->returns($variants, $start->toDateString(), $end->toDateString(), $filters);

        return [
            'params' => compact('days', 'targetDays', 'leadDays', 'batch', 'deadDays') + ['start' => $start, 'end' => $end, 'filtered' => $filters !== []],
            'kpi' => [
                'sold' => $variants->sum('sold'),
                'bill' => $variants->sum('bill'),
                'hpp' => $variants->sum('hpp_amount'),
                'out_of_stock' => $variants->filter(fn (array $v): bool => $v['sold'] > 0 && $v['stock'] <= 0)->count(),
                'urgent' => $variants->where('priority', '2 SEGERA')->count(),
                'suggested' => $variants->sum('suggested'),
                'priority_count' => $priority->count(),
                'in_production' => $variants->sum('in_production'),
            ],
            'previous' => $previous,
            'top_models' => $this->models($variants, $days),
            'top_variants' => $variants->where('sold', '>', 0)->sortByDesc('sold')->values(),
            'priority' => $priority,
            'margins' => $this->margins($variants),
            'dead_stock' => $this->deadStock($variants, $end, $deadDays),
            'returns' => $returns,
        ];
    }

    /**
     * Filter penjualan (toko / channel / reseller) & produk (kategori).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, list<int>>
     */
    private function filters(array $params): array
    {
        return collect(['store_ids', 'channel_ids', 'reseller_ids', 'category_ids'])
            ->mapWithKeys(fn (string $key): array => [$key => array_values(array_filter(array_map('intval', (array) ($params[$key] ?? []))))])
            ->filter()
            ->all();
    }

    /**
     * Potongan WHERE untuk baris penjualan (alias v_sales_lines) sesuai filter.
     *
     * @param  array<string, list<int>>  $filters
     * @param  list<mixed>  $bindings
     */
    private function salesWhere(string $alias, array $filters, array &$bindings): string
    {
        $sql = '';

        foreach (['store_ids' => 'store_id', 'channel_ids' => 'channel_id', 'reseller_ids' => 'reseller_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $sql .= " AND {$alias}.{$column} = ANY(?::bigint[])";
                $bindings[] = '{'.implode(',', $filters[$key]).'}';
            }
        }

        if (isset($filters['category_ids'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM product_variants fv JOIN product_models fm ON fm.id = fv.model_id
                                  WHERE fv.id = {$alias}.variant_id AND fm.category_id = ANY(?::bigint[]))";
            $bindings[] = '{'.implode(',', $filters['category_ids']).'}';
        }

        return $sql;
    }

    /**
     * Penjualan, stok, SPK berjalan, HPP, dan retur per varian dalam periode.
     *
     * @param  array<string, list<int>>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function variants(string $start, string $end, array $filters): Collection
    {
        $bindings = [$start, $end];
        $salesWhere = $this->salesWhere('l', $filters, $bindings);
        $bindings[] = $end;
        $lastWhere = $this->salesWhere('l', $filters, $bindings);
        array_push($bindings, $start, $end);
        $returnsWhere = $this->salesWhere('l', $filters, $bindings);
        $bindings[] = $end;

        $categoryWhere = '';
        if (isset($filters['category_ids'])) {
            $categoryWhere = ' AND m.category_id = ANY(?::bigint[])';
            $bindings[] = '{'.implode(',', $filters['category_ids']).'}';
        }

        $rows = DB::select(<<<SQL
            WITH sold AS (
                SELECT l.variant_id,
                       SUM(l.qty) FILTER (WHERE l.status NOT IN ('cancelled', 'returned')) AS sold,
                       SUM(l.qty) FILTER (WHERE l.status <> 'cancelled') AS sold_gross,
                       SUM(l.bill_amount) FILTER (WHERE l.status <> 'cancelled') AS bill,
                       SUM(l.hpp_amount) FILTER (WHERE l.status <> 'cancelled') AS hpp_amount
                FROM v_sales_lines l
                WHERE l.order_date BETWEEN ? AND ? {$salesWhere}
                GROUP BY 1),
            last_sale AS (
                SELECT l.variant_id, MAX(l.order_date) AS last_sold
                FROM v_sales_lines l
                WHERE l.status <> 'cancelled' AND l.order_date <= ? {$lastWhere}
                GROUP BY 1),
            st AS (SELECT b.variant_id, SUM(b.qty) AS stock FROM stock_balances b GROUP BY 1),
            wip AS (
                SELECT wi.variant_id, SUM(wi.qty_cut - wi.qty_good - wi.qty_reject) AS in_production
                FROM work_order_items wi
                JOIN work_orders w ON w.id = wi.work_order_id
                WHERE w.status IN ('cutting', 'sewing')
                GROUP BY 1),
            rt AS (
                SELECT l.variant_id, SUM(r.qty) AS returned, SUM(r.qty * l.reseller_unit_price) AS returned_value,
                       SUM(r.qty) FILTER (WHERE r.restock) AS restocked,
                       mode() WITHIN GROUP (ORDER BY r.reason) AS top_reason,
                       string_agg(DISTINCT NULLIF(trim(r.note), ''), '; ') AS notes
                FROM sales_returns r
                JOIN v_sales_lines l ON l.id = r.sales_order_item_id
                WHERE r.returned_at BETWEEN ? AND ? {$returnsWhere}
                GROUP BY 1),
            hpp AS (
                SELECT DISTINCT ON (variant_id) variant_id, total_cost
                FROM variant_costs WHERE effective_from <= ?
                ORDER BY variant_id, effective_from DESC)
            SELECT v.id, v.sku, v.name, m.id AS model_id, m.code AS model_code, m.name AS model_name, m.kind,
                   c.name AS category, sz.code AS size,
                   COALESCE(sold.sold, 0) AS sold, COALESCE(sold.sold_gross, 0) AS sold_gross,
                   COALESCE(sold.bill, 0) AS bill, COALESCE(sold.hpp_amount, 0) AS hpp_amount,
                   COALESCE(st.stock, 0) AS stock, COALESCE(wip.in_production, 0) AS in_production,
                   COALESCE(hpp.total_cost, 0) AS hpp_unit, last_sale.last_sold,
                   COALESCE(rt.returned, 0) AS returned, COALESCE(rt.returned_value, 0) AS returned_value,
                   COALESCE(rt.restocked, 0) AS restocked, rt.top_reason, rt.notes
            FROM product_variants v
            JOIN product_models m ON m.id = v.model_id
            LEFT JOIN product_categories c ON c.id = m.category_id
            LEFT JOIN sizes sz ON sz.id = v.size_id
            LEFT JOIN sold ON sold.variant_id = v.id
            LEFT JOIN last_sale ON last_sale.variant_id = v.id
            LEFT JOIN st ON st.variant_id = v.id
            LEFT JOIN wip ON wip.variant_id = v.id
            LEFT JOIN rt ON rt.variant_id = v.id
            LEFT JOIN hpp ON hpp.variant_id = v.id
            WHERE v.deleted_at IS NULL AND (v.is_active OR sold.sold IS NOT NULL OR rt.returned IS NOT NULL) {$categoryWhere}
            SQL, $bindings);

        return collect($rows)->map(fn (object $row): array => [
            ...(array) $row,
            'sold' => (int) $row->sold,
            'sold_gross' => (int) $row->sold_gross,
            'bill' => (float) $row->bill,
            'hpp_amount' => (float) $row->hpp_amount,
            'stock' => (int) $row->stock,
            'in_production' => (int) $row->in_production,
            'hpp_unit' => (float) $row->hpp_unit,
            'returned' => (int) $row->returned,
            'returned_value' => (float) $row->returned_value,
            'restocked' => (int) $row->restocked,
            'top_reason' => ReturnReason::tryFrom((string) $row->top_reason)?->getLabel(),
            'model' => trim($row->model_code.' | '.$row->model_name, ' |'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withPriority(array $row, int $days, int $targetDays, int $leadDays, int $batch): array
    {
        $perDay = $row['sold'] / $days;
        $isProduction = $row['kind'] === 'produksi';
        $available = $row['stock'] + $row['in_production'];
        $daysOfStock = $perDay > 0 ? max($available, 0) / $perDay : null;

        $priority = match (true) {
            ! $isProduction || $perDay <= 0 => '-',
            $available <= 0 => '1 HABIS',
            $daysOfStock <= $leadDays => '2 SEGERA',
            $daysOfStock <= $targetDays + $leadDays => '3 PERLU',
            default => 'AMAN',
        };

        $suggested = $isProduction && $perDay > 0
            ? (int) (ceil(max($perDay * ($targetDays + $leadDays) - $available, 0) / $batch) * $batch)
            : 0;

        return [
            ...$row,
            'available' => $available,
            'per_day' => $perDay,
            'days_of_stock' => $daysOfStock,
            'suggested' => $suggested,
            'priority' => $priority,
            'priority_rank' => self::PRIORITY_ORDER[$priority],
        ];
    }

    /**
     * Rekap per model (diurutkan terlaris).
     *
     * @param  Collection<int, array<string, mixed>>  $variants
     * @return Collection<int, array<string, mixed>>
     */
    private function models(Collection $variants, int $days): Collection
    {
        return $variants
            ->groupBy('model_id')
            ->map(function (Collection $rows) use ($days): array {
                $sold = $rows->sum('sold');
                $available = $rows->sum('available');
                $perDay = $sold / $days;

                return [
                    'model' => $rows->first()['model'],
                    'category' => $rows->first()['category'],
                    'sold' => $sold,
                    'bill' => $rows->sum('bill'),
                    'stock' => $rows->sum('stock'),
                    'in_production' => $rows->sum('in_production'),
                    'days_of_stock' => $perDay > 0 ? max($available, 0) / $perDay : null,
                    'suggested' => $rows->sum('suggested'),
                ];
            })
            ->where('sold', '>', 0)
            ->sortByDesc('sold')
            ->values();
    }

    /**
     * Margin konveksi per model: omzet tagihan reseller vs HPP barang terjual (snapshot saat jual).
     *
     * @param  Collection<int, array<string, mixed>>  $variants
     * @return Collection<int, array<string, mixed>>
     */
    private function margins(Collection $variants): Collection
    {
        return $variants
            ->where('bill', '>', 0)
            ->groupBy('model_id')
            ->map(function (Collection $rows): array {
                $bill = $rows->sum('bill');
                $hpp = $rows->sum('hpp_amount');
                $sold = $rows->sum('sold_gross');

                return [
                    'model' => $rows->first()['model'],
                    'category' => $rows->first()['category'],
                    'sold' => $sold,
                    'bill' => $bill,
                    'hpp' => $hpp,
                    'profit' => $bill - $hpp,
                    'margin' => $bill > 0 ? ($bill - $hpp) / $bill : null,
                    'profit_per_pcs' => $sold > 0 ? ($bill - $hpp) / $sold : null,
                ];
            })
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * Stok lambat laku: stok > 0 dan tidak terjual >= $deadDays hari (atau belum pernah terjual).
     *
     * @param  Collection<int, array<string, mixed>>  $variants
     * @return Collection<int, array<string, mixed>>
     */
    private function deadStock(Collection $variants, Carbon $end, int $deadDays): Collection
    {
        return $variants
            ->filter(fn (array $v): bool => $v['stock'] > 0
                && ($v['last_sold'] === null || Carbon::parse($v['last_sold'])->diffInDays($end) >= $deadDays))
            ->map(fn (array $v): array => [
                ...$v,
                'idle_days' => $v['last_sold'] === null ? null : (int) Carbon::parse($v['last_sold'])->diffInDays($end),
                'stock_value' => $v['stock'] * $v['hpp_unit'],
            ])
            ->sortByDesc('stock_value')
            ->values();
    }

    /**
     * Total penjualan & retur sebuah periode (untuk pembanding periode sebelumnya).
     *
     * @param  array<string, list<int>>  $filters
     * @return array{sold: int, bill: float, returned: int, sold_gross: int, rate: ?float}
     */
    private function totals(string $start, string $end, array $filters): array
    {
        $bindings = [$start, $end];
        $where = $this->salesWhere('l', $filters, $bindings);
        $sales = DB::selectOne(<<<SQL
            SELECT COALESCE(SUM(l.qty) FILTER (WHERE l.status NOT IN ('cancelled', 'returned')), 0) AS sold,
                   COALESCE(SUM(l.qty) FILTER (WHERE l.status <> 'cancelled'), 0) AS sold_gross,
                   COALESCE(SUM(l.bill_amount) FILTER (WHERE l.status <> 'cancelled'), 0) AS bill
            FROM v_sales_lines l WHERE l.order_date BETWEEN ? AND ? {$where}
            SQL, $bindings);

        $bindings = [$start, $end];
        $where = $this->salesWhere('l', $filters, $bindings);
        $returned = (int) DB::scalar(<<<SQL
            SELECT COALESCE(SUM(r.qty), 0) FROM sales_returns r JOIN v_sales_lines l ON l.id = r.sales_order_item_id
            WHERE r.returned_at BETWEEN ? AND ? {$where}
            SQL, $bindings);

        return [
            'sold' => (int) $sales->sold,
            'bill' => (float) $sales->bill,
            'returned' => $returned,
            'sold_gross' => (int) $sales->sold_gross,
            'rate' => $sales->sold_gross > 0 ? $returned / $sales->sold_gross : null,
        ];
    }

    /**
     * Analisa retur dalam periode (berdasarkan tanggal retur).
     *
     * @param  Collection<int, array<string, mixed>>  $variants
     * @param  array<string, list<int>>  $filters
     * @return array<string, mixed>
     */
    private function returns(Collection $variants, string $start, string $end, array $filters): array
    {
        $returned = $variants->where('returned', '>', 0);
        $qty = $returned->sum('returned');
        $soldGross = $variants->sum('sold_gross');

        $bindings = [$start, $end];
        $where = $this->salesWhere('l', $filters, $bindings);
        array_push($bindings, $start, $end);
        $soldWhere = $this->salesWhere('l', $filters, $bindings);

        $byStore = collect(DB::select(<<<SQL
            WITH rt AS (
                SELECT l.store_id, SUM(r.qty) AS returned, SUM(r.qty * l.reseller_unit_price) AS returned_value
                FROM sales_returns r
                JOIN v_sales_lines l ON l.id = r.sales_order_item_id
                WHERE r.returned_at BETWEEN ? AND ? {$where}
                GROUP BY 1),
            sold AS (
                SELECT l.store_id, SUM(l.qty) AS sold FROM v_sales_lines l
                WHERE l.status <> 'cancelled' AND l.order_date BETWEEN ? AND ? {$soldWhere}
                GROUP BY 1)
            SELECT s.name AS store, COALESCE(sold.sold, 0) AS sold, rt.returned, rt.returned_value
            FROM rt
            JOIN stores s ON s.id = rt.store_id
            LEFT JOIN sold ON sold.store_id = rt.store_id
            ORDER BY rt.returned DESC
            SQL, $bindings))
            ->map(fn (object $row): array => [
                'store' => $row->store,
                'sold' => (int) $row->sold,
                'returned' => (int) $row->returned,
                'returned_value' => (float) $row->returned_value,
                'rate' => $row->sold > 0 ? $row->returned / $row->sold : null,
            ]);

        $bindings = [$start, $end];
        $where = $this->salesWhere('l', $filters, $bindings);
        $byReason = collect(DB::select(<<<SQL
            SELECT r.reason, SUM(r.qty) AS returned, SUM(r.qty * l.reseller_unit_price) AS returned_value, COUNT(DISTINCT l.variant_id) AS sku_count
            FROM sales_returns r
            JOIN v_sales_lines l ON l.id = r.sales_order_item_id
            WHERE r.returned_at BETWEEN ? AND ? {$where}
            GROUP BY 1
            ORDER BY 2 DESC
            SQL, $bindings))
            ->map(fn (object $row): array => [
                'reason' => ReturnReason::tryFrom((string) $row->reason)?->getLabel() ?? 'Belum diisi',
                'reason_key' => $row->reason,
                'returned' => (int) $row->returned,
                'share' => $qty > 0 ? $row->returned / $qty : null,
                'returned_value' => (float) $row->returned_value,
                'sku_count' => (int) $row->sku_count,
            ]);

        return [
            'qty' => $qty,
            'value' => $returned->sum('returned_value'),
            'rate' => $soldGross > 0 ? $qty / $soldGross : null,
            'restocked' => $returned->sum('restocked'),
            'sku_count' => $returned->count(),
            'production_defects' => $byReason->whereIn('reason_key', [ReturnReason::CacatJahit->value, ReturnReason::CacatBahan->value])->sum('returned'),
            'by_variant' => $returned
                ->map(fn (array $v): array => [...$v, 'return_rate' => $v['sold_gross'] > 0 ? $v['returned'] / $v['sold_gross'] : null])
                ->sortByDesc('returned')
                ->values(),
            'by_model' => $returned
                ->groupBy('model_id')
                ->map(function (Collection $rows): array {
                    $sold = $rows->sum('sold_gross');

                    return [
                        'model' => $rows->first()['model'],
                        'returned' => $rows->sum('returned'),
                        'sold' => $sold,
                        'rate' => $sold > 0 ? $rows->sum('returned') / $sold : null,
                        'value' => $rows->sum('returned_value'),
                    ];
                })
                ->sortByDesc('returned')
                ->values(),
            'by_store' => $byStore->values(),
            'by_reason' => $byReason->values(),
        ];
    }

    /**
     * Resep x saran produksi -> kebutuhan bahan (satuan dasar), dibanding saldo bahan semua gudang.
     *
     * @param  array<string, mixed>  $dashboard
     * @return array{rows: Collection<int, array<string, mixed>>, missing: list<string>}
     */
    private function computeMaterialNeeds(array $dashboard): array
    {
        $date = today();
        $needs = [];
        $missing = [];
        $suggested = $dashboard['priority']->where('suggested', '>', 0);
        $variants = ProductVariant::query()->whereIn('id', $suggested->pluck('id'))->get()->keyBy('id');

        foreach ($suggested as $row) {
            $variant = $variants->get($row['id']);
            $bom = $variant ? $this->costing->bomFor($variant, $date) : null;

            if ($bom === null) {
                $missing[] = "{$row['sku']}: belum punya resep";

                continue;
            }

            foreach ($bom->items->where('line_type', BomLineType::Material) as $item) {
                $material = $this->costing->resolveMaterial($item, $variant);

                if ($material === null) {
                    $missing[] = "{$row['sku']}: bahan {$item->materialType?->name} warna varian belum terdaftar";

                    continue;
                }

                $needs[$material->id] ??= ['material_id' => $material->id, 'code' => $material->code, 'uom' => $material->base_uom->value,
                    'pack_uom' => $material->pack_uom, 'qty_per_pack' => (float) $material->qty_per_pack, 'needed' => 0.0, 'skus' => 0];
                $needs[$material->id]['needed'] += (float) $item->qty_per_unit * $row['suggested'];
                $needs[$material->id]['skus']++;
            }
        }

        $stock = DB::table('material_balances')->whereIn('material_id', array_keys($needs))
            ->groupBy('material_id')->selectRaw('material_id, SUM(qty) AS qty')->pluck('qty', 'material_id');

        $rows = collect($needs)
            ->map(function (array $row) use ($stock): array {
                $available = (float) ($stock[$row['material_id']] ?? 0);
                $shortage = max($row['needed'] - $available, 0);

                return [
                    ...$row,
                    'stock' => $available,
                    'shortage' => $shortage,
                    'packs_to_buy' => $shortage > 0 && $row['qty_per_pack'] > 0 ? (int) ceil($shortage / $row['qty_per_pack']) : 0,
                    'status' => $shortage > 0 ? 'KURANG' : 'CUKUP',
                ];
            })
            ->sortBy([['status', 'desc'], ['shortage', 'desc']])
            ->values();

        return ['rows' => $rows, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function key(array $params): string
    {
        ksort($params);

        return md5(json_encode($params));
    }
}
