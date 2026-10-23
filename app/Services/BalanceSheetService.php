<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NERACA per sumber (konveksi & tiap toko) per tanggal, disusun dari transaksi tercatat.
 *
 * Sistem belum memakai jurnal berpasangan, jadi:
 * - kas = penerimaan - pengeluaran tercatat (tanpa saldo awal kas),
 * - ekuitas memuat pos penyeimbang (modal, saldo awal & selisih) agar Aset = Kewajiban + Ekuitas.
 */
class BalanceSheetService
{
    public const KONVEKSI = 'konveksi';

    public const ALL_STORES = 'stores';

    /**
     * @return array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>
     */
    public function build(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->startOfDay();

        $sheets = [self::KONVEKSI => $this->konveksi($asOf)];
        $stores = $this->stores($asOf);

        if ($stores !== []) {
            $sheets[self::ALL_STORES] = $this->combine('Semua Toko (gabungan)', $stores);
        }

        return $sheets + $stores;
    }

    /**
     * @param  list<array{label: string, amount: float}>  $lines
     */
    public static function total(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }

    /**
     * @return array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}
     */
    private function konveksi(Carbon $asOf): array
    {
        $date = $asOf->toDateString();
        $nextDay = $asOf->copy()->addDay()->toDateString();
        $yearStart = $asOf->copy()->startOfYear()->toDateString();

        $cashIn = $this->scalar(
            "SELECT SUM(amount) FROM reseller_payments WHERE method <> 'cost_offset' AND paid_at < ?", [$nextDay]);
        $cashOut = $this->scalar(<<<'SQL'
            SELECT COALESCE((SELECT SUM(amount) FROM expenses WHERE reseller_payment_id IS NULL AND expense_date <= ?), 0)
                 + COALESCE((SELECT SUM(amount) FROM tailor_payments WHERE paid_at <= ?), 0)
                 + COALESCE((SELECT SUM(i.total_price) FROM material_purchase_items i
                             JOIN material_purchases p ON p.id = i.purchase_id WHERE p.purchase_date <= ?), 0)
                 + COALESCE((SELECT SUM(i.qty * COALESCE(i.unit_cost, 0)) FROM goods_receipt_items i
                             JOIN goods_receipts r ON r.id = i.receipt_id
                             WHERE (r.source = 'borongan' OR (r.source = 'beli_jadi' AND r.supplier_id IS NULL)) AND r.received_at < ?), 0)
                 + COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE paid_at <= ?), 0)
            SQL, [$date, $date, $date, $nextDay, $date]);

        // tagihan supplier beli jadi (dari pesanan, FIFO lot): positif = utang, negatif = uang muka ke supplier
        $supplierBalances = array_map('floatval', array_column(DB::select(<<<'SQL'
            SELECT SUM(amount) AS balance FROM (
                SELECT supplier_id, amount FROM supplier_bill_lines WHERE supplier_id IS NOT NULL AND billed_on <= ?
                UNION ALL
                SELECT supplier_id, -amount FROM supplier_payments WHERE paid_at <= ?
            ) x GROUP BY supplier_id
            SQL, [$date, $date]), 'balance'));

        // saldo per reseller: positif = piutang, negatif = uang muka / titipan reseller
        $resellerBalances = array_map('floatval', DB::table('resellers as r')
            ->selectRaw(<<<'SQL'
                CASE WHEN r.opening_balance_date IS NULL OR r.opening_balance_date <= ? THEN r.opening_balance ELSE 0 END
                + COALESCE((SELECT SUM(bill_amount) FROM v_sales_lines l
                            WHERE l.reseller_id = r.id AND l.status <> 'cancelled' AND l.order_date <= ?), 0)
                - COALESCE((SELECT SUM(sr.qty * i.reseller_unit_price) FROM sales_returns sr
                            JOIN sales_order_items i ON i.id = sr.sales_order_item_id
                            JOIN sales_orders o ON o.id = i.sales_order_id
                            JOIN stores st ON st.id = o.store_id
                            WHERE st.reseller_id = r.id AND sr.credit_reseller AND sr.returned_at <= ?), 0)
                - COALESCE((SELECT SUM(amount) FROM reseller_payments p WHERE p.reseller_id = r.id AND p.paid_at < ?), 0)
                AS balance
                SQL, [$date, $date, $date, $nextDay])
            ->pluck('balance')
            ->all());

        // barang jadi: qty per tanggal x HPP yang berlaku (bila belum ada, HPP pertama yang tercatat)
        $finishedGoods = $this->scalar(<<<'SQL'
            WITH q AS (SELECT variant_id, SUM(qty) AS qty FROM stock_movements WHERE moved_at < ? GROUP BY 1)
            SELECT SUM(GREATEST(q.qty, 0) * COALESCE(c.total_cost, 0))
            FROM q
            LEFT JOIN LATERAL (
                SELECT vc.total_cost FROM variant_costs vc WHERE vc.variant_id = q.variant_id AND vc.effective_from <= ?
                ORDER BY (vc.effective_from <= ?) DESC,
                         CASE WHEN vc.effective_from <= ? THEN vc.effective_from END DESC,
                         vc.effective_from
                LIMIT 1) c ON true
            SQL, [$nextDay, $date, $date, $date]);

        // bahan: qty per tanggal x rata-rata harga beli s.d. tanggal (fallback: rata-rata unit_cost di kartu bahan)
        $materials = $this->scalar(<<<'SQL'
            WITH q AS (SELECT material_id, SUM(qty) AS qty FROM material_movements WHERE moved_at < ? GROUP BY 1),
                 pc AS (SELECT i.material_id, SUM(i.total_price) / NULLIF(SUM(i.qty_base), 0) AS unit_cost
                        FROM material_purchase_items i JOIN material_purchases p ON p.id = i.purchase_id
                        WHERE p.purchase_date <= ? GROUP BY 1),
                 mc AS (SELECT material_id, AVG(unit_cost) AS unit_cost FROM material_movements
                        WHERE unit_cost IS NOT NULL AND moved_at < ? GROUP BY 1)
            SELECT SUM(GREATEST(q.qty, 0) * COALESCE(pc.unit_cost, mc.unit_cost, 0))
            FROM q LEFT JOIN pc USING (material_id) LEFT JOIN mc USING (material_id)
            SQL, [$nextDay, $date, $nextDay]);

        // barang dalam proses: SPK sudah dipotong tapi belum selesai per tanggal (nilai bahan terpakai)
        $workInProgress = $this->scalar(<<<'SQL'
            SELECT SUM(i.qty_cut * COALESCE(i.material_cost_snapshot, 0))
            FROM work_order_items i JOIN work_orders w ON w.id = i.work_order_id
            WHERE w.cut_date <= ? AND w.status <> 'cancelled'
              AND (w.status IN ('cutting', 'sewing') OR w.finished_date > ?)
            SQL, [$date, $date]);

        // upah jahit (SPK selesai) + upah potong (SPK dipotong) per orang: positif = utang upah, negatif = uang muka
        $tailorBalances = array_map('floatval', array_column(DB::select(<<<'SQL'
            SELECT SUM(amount) AS balance FROM (
                SELECT w.tailor_id AS worker_id, i.qty_good * COALESCE(i.sewing_cost_snapshot, 0) AS amount
                FROM work_order_items i JOIN work_orders w ON w.id = i.work_order_id
                WHERE w.status = 'done' AND w.finished_date <= ?
                UNION ALL
                SELECT w.cutter_id, i.qty_cut * COALESCE(i.cutting_cost_snapshot, 0)
                FROM work_order_items i JOIN work_orders w ON w.id = i.work_order_id
                WHERE w.status <> 'cancelled' AND w.cut_date <= ?
                UNION ALL
                SELECT tailor_id, -amount FROM tailor_payments WHERE paid_at <= ?
            ) x GROUP BY worker_id
            SQL, [$date, $date, $date]), 'balance'));

        $ytdProfit = $this->scalar(<<<'SQL'
            SELECT COALESCE((SELECT SUM(bill_amount - hpp_amount) FROM v_sales_lines
                             WHERE status <> 'cancelled' AND order_date BETWEEN ? AND ?), 0)
                 - COALESCE((SELECT SUM(CASE WHEN sr.credit_reseller THEN sr.qty * i.reseller_unit_price ELSE 0 END - CASE WHEN sr.restock THEN sr.qty * i.hpp_unit ELSE 0 END) FROM sales_returns sr
                             JOIN sales_order_items i ON i.id = sr.sales_order_item_id
                             WHERE sr.returned_at BETWEEN ? AND ?), 0)
                 - COALESCE((SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?), 0)
            SQL, [$yearStart, $date, $yearStart, $date, $yearStart, $date]);

        $assets = [
            ['label' => 'Kas & Bank (arus kas tercatat)', 'amount' => round($cashIn - $cashOut, 2)],
            ['label' => 'Piutang Reseller', 'amount' => $this->positive($resellerBalances)],
            ['label' => 'Persediaan Barang Jadi', 'amount' => round($finishedGoods, 2)],
            ['label' => 'Persediaan Bahan Baku', 'amount' => round($materials, 2)],
            ['label' => 'Barang Dalam Proses (SPK berjalan)', 'amount' => round($workInProgress, 2)],
            ['label' => 'Uang Muka Penjahit / Tukang Potong', 'amount' => $this->negative($tailorBalances), 'hide_zero' => true],
            ['label' => 'Uang Muka Supplier', 'amount' => $this->negative($supplierBalances), 'hide_zero' => true],
        ];

        $liabilities = [
            ['label' => 'Utang Upah Jahit & Potong', 'amount' => $this->positive($tailorBalances)],
            ['label' => 'Utang Supplier (barang jadi)', 'amount' => $this->positive($supplierBalances), 'hide_zero' => true],
            ['label' => 'Titipan / Uang Muka Reseller', 'amount' => $this->negative($resellerBalances), 'hide_zero' => true],
        ];

        return $this->sheet('Konveksi', $assets, $liabilities, $ytdProfit, 'Modal, Saldo Awal & Laba Ditahan');
    }

    /**
     * @return array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>
     */
    private function stores(Carbon $asOf): array
    {
        $date = $asOf->toDateString();
        $nextDay = $asOf->copy()->addDay()->toDateString();
        $month = $asOf->copy()->startOfMonth()->toDateString();
        $yearStart = $asOf->copy()->startOfYear()->toDateString();

        $billing = collect(DB::select(<<<'SQL'
            SELECT store_id, SUM(bill_amount) AS billed,
                   SUM(bill_amount) FILTER (WHERE revenue IS NULL AND status <> 'returned') AS pending
            FROM v_sales_lines WHERE status <> 'cancelled' AND order_date <= ? GROUP BY 1
            SQL, [$date]))->keyBy('store_id');

        $returns = collect(DB::select(<<<'SQL'
            SELECT o.store_id, SUM(sr.qty * i.reseller_unit_price) AS credit
            FROM sales_returns sr
            JOIN sales_order_items i ON i.id = sr.sales_order_item_id
            JOIN sales_orders o ON o.id = i.sales_order_id
            WHERE sr.credit_reseller AND sr.returned_at <= ? GROUP BY 1
            SQL, [$date]))->keyBy('store_id');

        // laba rugi toko dari view bulanan (s.d. bulan tanggal neraca)
        $profit = collect(DB::select(<<<'SQL'
            SELECT store_id, SUM(revenue) AS revenue, SUM(admin_fee) AS admin_fee, SUM(ads) AS ads,
                   SUM(net_profit) AS net_profit, SUM(net_profit) FILTER (WHERE month >= ?) AS ytd_profit
            FROM fn_store_pl(?) GROUP BY 1
            SQL, [$yearStart, $date]))->keyBy('store_id');

        $payments = DB::table('reseller_payments')
            ->where('paid_at', '<', $nextDay)
            ->groupBy('reseller_id')
            ->selectRaw('reseller_id, SUM(amount) AS paid')
            ->pluck('paid', 'reseller_id');

        $netBilled = fn (int $storeId): float => (float) ($billing[$storeId]->billed ?? 0) - (float) ($returns[$storeId]->credit ?? 0);

        $stores = Store::withTrashed()->with('reseller:id,name')->orderBy('name')->get()
            ->filter(fn (Store $store): bool => ! $store->trashed() || isset($billing[$store->id]) || isset($profit[$store->id]));

        // pembayaran tercatat per reseller, dialokasikan ke toko sebanding tagihan bersih tiap toko
        $resellerNetBilled = $stores->groupBy('reseller_id')
            ->map(fn ($group): float => $group->sum(fn (Store $store): float => max($netBilled($store->id), 0)));

        $sheets = [];

        foreach ($stores as $store) {
            $billed = $netBilled($store->id);
            $resellerTotal = (float) $resellerNetBilled[$store->reseller_id];
            $paid = $resellerTotal > 0 ? (float) ($payments[$store->reseller_id] ?? 0) * max($billed, 0) / $resellerTotal : 0.0;
            $payable = $billed - $paid;

            $pl = $profit[$store->id] ?? null;
            $revenue = (float) ($pl->revenue ?? 0);
            $fees = (float) ($pl->admin_fee ?? 0) + (float) ($pl->ads ?? 0);

            $assets = [
                ['label' => 'Kas Hasil Penjualan (estimasi)', 'amount' => round($revenue - $fees - $paid, 2)],
                ['label' => 'Penjualan Menunggu Data Pendapatan', 'amount' => round((float) ($billing[$store->id]->pending ?? 0), 2)],
                ['label' => 'Lebih Bayar ke Konveksi', 'amount' => round(max(-$payable, 0), 2), 'hide_zero' => true],
            ];

            $liabilities = [
                ['label' => 'Utang ke Konveksi', 'amount' => round(max($payable, 0), 2)],
            ];

            $sheets['store:'.$store->id] = $this->sheet(
                $store->name.' ('.$store->reseller?->name.')',
                $assets,
                $liabilities,
                (float) ($pl->ytd_profit ?? 0),
                'Laba Ditahan & Selisih Retur',
            );
        }

        return $sheets;
    }

    /**
     * @param  list<array{label: string, amount: float, hide_zero?: bool}>  $assets
     * @param  list<array{label: string, amount: float, hide_zero?: bool}>  $liabilities
     * @return array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}
     */
    private function sheet(string $label, array $assets, array $liabilities, float $ytdProfit, string $balancingLabel): array
    {
        $ytdProfit = round($ytdProfit, 2);
        $assets = $this->visibleLines($assets);
        $liabilities = $this->visibleLines($liabilities);

        return [
            'label' => $label,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => [
                ['label' => $balancingLabel, 'amount' => round(self::total($assets) - self::total($liabilities) - $ytdProfit, 2) + 0.0],
                ['label' => 'Laba Tahun Berjalan', 'amount' => $ytdProfit],
            ],
            'ytd_profit' => $ytdProfit,
        ];
    }

    /**
     * @param  array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>  $sheets
     * @return array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}
     */
    private function combine(string $label, array $sheets): array
    {
        $sum = function (string $section) use ($sheets): array {
            $lines = [];

            foreach ($sheets as $sheet) {
                foreach ($sheet[$section] as $line) {
                    $lines[$line['label']] = round(($lines[$line['label']] ?? 0) + $line['amount'], 2);
                }
            }

            return array_map(fn (string $name, float $amount): array => ['label' => $name, 'amount' => $amount], array_keys($lines), $lines);
        };

        return [
            'label' => $label,
            'assets' => $sum('assets'),
            'liabilities' => $sum('liabilities'),
            'equity' => $sum('equity'),
            'ytd_profit' => round(array_sum(array_column($sheets, 'ytd_profit')), 2),
        ];
    }

    /**
     * Pos lawan (uang muka / lebih bayar) hanya tampil bila ada saldonya.
     *
     * @param  list<array{label: string, amount: float, hide_zero?: bool}>  $lines
     * @return list<array{label: string, amount: float}>
     */
    private function visibleLines(array $lines): array
    {
        return array_values(array_map(
            fn (array $line): array => ['label' => $line['label'], 'amount' => $line['amount']],
            array_filter($lines, fn (array $line): bool => $line['amount'] != 0 || ! ($line['hide_zero'] ?? false)),
        ));
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings): float
    {
        return (float) (DB::scalar($sql, $bindings) ?? 0);
    }

    /**
     * @param  list<float>  $balances
     */
    private function positive(array $balances): float
    {
        return round(array_sum(array_map(fn (float $balance): float => max($balance, 0), $balances)), 2);
    }

    /**
     * @param  list<float>  $balances
     */
    private function negative(array $balances): float
    {
        return round(array_sum(array_map(fn (float $balance): float => max(-$balance, 0), $balances)), 2);
    }
}
