<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\LedgerClosing;
use App\Support\ChartOfAccounts;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BUKU BESAR double-entry.
 *
 * Jurnal otomatis disusun ulang dari transaksi (penjualan, retur, pembayaran, biaya, pembelian, SPK, upah,
 * supplier, stok, modal, laba rugi toko, pajak) setiap kali ada perubahan (penanda dari trigger database),
 * kecuali periode yang sudah ditutup. Jurnal manual tidak pernah disentuh.
 *
 * Entitas: 'konveksi' (store_id NULL), 'stores' (semua toko), 'store:{id}'.
 */
class GeneralLedger
{
    public const KONVEKSI = 'konveksi';

    public const ALL_STORES = 'stores';

    // ------------------------------------------------------------------ PENYUSUNAN JURNAL OTOMATIS

    /**
     * Susun ulang jurnal otomatis bila ada transaksi berubah sejak penyusunan terakhir.
     */
    public function ensureFresh(): void
    {
        $stale = DB::scalar('SELECT synced_at IS NULL OR dirty_at > synced_at FROM ledger_state WHERE id = 1');

        if ($stale !== false) {
            $this->sync();
        }
    }

    /**
     * Penanda "perlu disusun ulang" (dipakai setelah perubahan yang tidak lewat tabel sumber).
     */
    public function markDirty(): void
    {
        DB::update('UPDATE ledger_state SET dirty_at = clock_timestamp() WHERE id = 1');
    }

    /**
     * @return array{entries: int, lines: int, drift: list<array{account: string, expected: float, posted: float}>}
     */
    public function sync(): array
    {
        return DB::transaction(function (): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('general_ledger_sync'))");

            $closedUntil = $this->closedUntil()?->toDateString() ?? '1900-01-01';

            DB::statement('DROP TABLE IF EXISTS tmp_postings');
            DB::statement(<<<'SQL'
                CREATE TEMP TABLE tmp_postings (
                    store_id bigint, entry_date date, source_type text, source_key text, description text,
                    role text, cash_account_id bigint, account_id bigint, amount numeric(15,2),
                    reseller_id bigint, supplier_id bigint, tailor_id bigint
                ) ON COMMIT DROP
                SQL);

            foreach ($this->rules() as $sql) {
                DB::statement('INSERT INTO tmp_postings (store_id, entry_date, source_type, source_key, description, role, cash_account_id, account_id, amount, reseller_id, supplier_id, tailor_id) '.$sql);
            }

            DB::statement('DELETE FROM tmp_postings WHERE amount IS NULL OR amount = 0 OR entry_date IS NULL');
            $this->resolveAccounts();
            $this->postTax();
            $this->resolveAccounts();

            $drift = $this->drift($closedUntil);

            DB::delete('DELETE FROM journal_entries WHERE is_auto AND entry_date > ?', [$closedUntil]);

            $entries = DB::affectingStatement(<<<'SQL'
                INSERT INTO journal_entries (store_id, entry_date, description, source_type, source_key, is_auto, created_at, updated_at)
                SELECT store_id, entry_date, MIN(description), source_type, source_key, true, now(), now()
                FROM tmp_postings WHERE entry_date > ?
                GROUP BY store_id, entry_date, source_type, source_key
                SQL, [$closedUntil]);

            $lines = DB::affectingStatement(<<<'SQL'
                INSERT INTO journal_lines (entry_id, account_id, entry_date, store_id, debit, credit, reseller_id, supplier_id, tailor_id)
                SELECT e.id, t.account_id, t.entry_date, t.store_id,
                       GREATEST(SUM(t.amount), 0), GREATEST(-SUM(t.amount), 0), t.reseller_id, t.supplier_id, t.tailor_id
                FROM tmp_postings t
                JOIN journal_entries e ON e.is_auto AND e.entry_date = t.entry_date AND e.source_type = t.source_type
                     AND COALESCE(e.source_key, '') = COALESCE(t.source_key, '') AND COALESCE(e.store_id, 0) = COALESCE(t.store_id, 0)
                WHERE t.entry_date > ?
                GROUP BY e.id, t.account_id, t.entry_date, t.store_id, t.reseller_id, t.supplier_id, t.tailor_id
                HAVING SUM(t.amount) <> 0
                SQL, [$closedUntil]);

            DB::delete('DELETE FROM journal_entries e WHERE e.is_auto AND e.entry_date > ? AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.entry_id = e.id)', [$closedUntil]);

            $unbalanced = DB::select(<<<'SQL'
                SELECT e.source_type, e.source_key, e.entry_date, SUM(l.debit) - SUM(l.credit) AS diff
                FROM journal_entries e JOIN journal_lines l ON l.entry_id = e.id
                WHERE e.is_auto AND e.entry_date > ? GROUP BY e.id HAVING SUM(l.debit) <> SUM(l.credit) LIMIT 5
                SQL, [$closedUntil]);

            if ($unbalanced !== []) {
                throw new RuntimeException('Jurnal otomatis tidak seimbang: '.json_encode($unbalanced));
            }

            DB::update('UPDATE ledger_state SET synced_at = now(), drift = ? WHERE id = 1', [json_encode($drift)]);

            return ['entries' => $entries, 'lines' => $lines, 'drift' => $drift];
        });
    }

    /**
     * Aturan jurnal otomatis. Setiap baris: debit = amount positif, kredit = amount negatif.
     * Kolom: store_id, entry_date, source_type, source_key, description, role, cash_account_id, account_id, amount, reseller_id, supplier_id, tailor_id.
     *
     * @return list<string>
     */
    private function rules(): array
    {
        // "Hari ini" dari aplikasi (bukan jam database) agar konsisten dengan laporan & pengujian.
        $today = "DATE '".today()->toDateString()."'";
        $openingDate = "COALESCE(r.opening_balance_date, (SELECT MIN(order_date) - 1 FROM sales_orders), {$today})";
        $variantCost = <<<'SQL'
            (SELECT vc.total_cost FROM variant_costs vc WHERE vc.variant_id = %1$s
             ORDER BY (vc.effective_from <= %2$s) DESC, CASE WHEN vc.effective_from <= %2$s THEN vc.effective_from END DESC, vc.effective_from LIMIT 1)
            SQL;
        $materialCost = <<<'SQL'
            COALESCE(%1$s.unit_cost,
                (SELECT SUM(i.total_price) / NULLIF(SUM(i.qty_base), 0) FROM material_purchase_items i JOIN material_purchases p ON p.id = i.purchase_id
                 WHERE i.material_id = %1$s.material_id AND p.purchase_date <= %1$s.moved_at::date),
                (SELECT AVG(x.unit_cost) FROM material_movements x WHERE x.material_id = %1$s.material_id AND x.unit_cost IS NOT NULL), 0)
            SQL;
        $cogsRole = "CASE WHEN c THEN 'consignment_cogs' WHEN kind = 'beli_jadi' THEN 'cogs_ready_made' ELSE 'cogs_production' END";

        return [
            // Saldo awal piutang / deposit reseller (konveksi) dan utang ke konveksi (toko pertama reseller).
            "SELECT NULL::bigint, {$openingDate}, 'opening', 'reseller:' || r.id, 'Saldo awal piutang ' || r.name, v.role, NULL::bigint, NULL::bigint, v.amount, v.reseller, NULL::bigint, NULL::bigint
             FROM resellers r CROSS JOIN LATERAL (VALUES ('ar_reseller', r.opening_balance, r.id), ('opening_equity', -r.opening_balance, NULL::bigint)) v(role, amount, reseller)
             WHERE r.opening_balance <> 0",
            "SELECT s.id, {$openingDate}, 'opening', 'reseller:' || r.id, 'Saldo awal utang ke konveksi', v.role, NULL, NULL, v.amount, r.id, NULL, NULL
             FROM resellers r JOIN LATERAL (SELECT MIN(id) AS id FROM stores WHERE reseller_id = r.id) s ON s.id IS NOT NULL
             CROSS JOIN LATERAL (VALUES ('store_opening_equity', r.opening_balance), ('store_payable', -r.opening_balance)) v(role, amount)
             WHERE r.opening_balance <> 0",

            // Penjualan per toko per hari: piutang / penjualan, HPP / persediaan barang jadi.
            "SELECT NULL, d, 'sales', 'store:' || store_id, 'Penjualan ' || store_name, v.role, NULL, NULL, v.amount, v.reseller, NULL, NULL
             FROM (SELECT l.order_date AS d, l.store_id, st.name AS store_name, l.reseller_id, m.kind, l.is_consignment AS c,
                          SUM(l.bill_amount) AS bill, SUM(l.hpp_amount) AS hpp
                   FROM v_sales_lines l JOIN stores st ON st.id = l.store_id
                   JOIN product_variants pv ON pv.id = l.variant_id JOIN product_models m ON m.id = pv.model_id
                   WHERE l.status <> 'cancelled' GROUP BY 1, 2, 3, 4, 5, 6) x
             CROSS JOIN LATERAL (VALUES
                ('ar_reseller', bill, reseller_id),
                (CASE WHEN c THEN 'consignment_sales' ELSE 'sales' END, -bill, NULL::bigint),
                ({$cogsRole}, hpp, NULL::bigint),
                ('inventory_fg', -hpp, NULL::bigint)) v(role, amount, reseller)",

            // Retur: kredit ke reseller (retur penjualan / piutang) & barang kembali ke stok (persediaan / HPP).
            "SELECT NULL, d, 'return', 'store:' || store_id, 'Retur ' || store_name, v.role, NULL, NULL, v.amount, v.reseller, NULL, NULL
             FROM (SELECT sr.returned_at AS d, o.store_id, st.name AS store_name, st.reseller_id, m.kind, i.is_consignment AS c,
                          COALESCE(SUM(sr.qty * i.reseller_unit_price) FILTER (WHERE sr.credit_reseller), 0) AS credit,
                          COALESCE(SUM(sr.qty * i.hpp_unit) FILTER (WHERE sr.restock), 0) AS hpp
                   FROM sales_returns sr JOIN sales_order_items i ON i.id = sr.sales_order_item_id
                   JOIN sales_orders o ON o.id = i.sales_order_id JOIN stores st ON st.id = o.store_id
                   JOIN product_variants pv ON pv.id = i.variant_id JOIN product_models m ON m.id = pv.model_id
                   GROUP BY 1, 2, 3, 4, 5, 6) x
             CROSS JOIN LATERAL (VALUES
                (CASE WHEN c THEN 'consignment_sales' ELSE 'sales_return' END, credit, NULL::bigint),
                ('ar_reseller', -credit, reseller_id),
                ('inventory_fg', hpp, NULL::bigint),
                ({$cogsRole}, -hpp, NULL::bigint)) v(role, amount, reseller)",

            // Pembayaran reseller: kas / piutang. Potongan biaya: penampung "dibayar reseller" / piutang.
            "SELECT NULL, (p.paid_at AT TIME ZONE 'Asia/Jakarta')::date, 'payment', 'payment:' || p.id,
                    CASE WHEN p.method = 'cost_offset' THEN 'Potongan biaya dibayar ' ELSE 'Pembayaran ' END || r.name || COALESCE(' - ' || p.note, ''),
                    v.role, v.cash, NULL, v.amount, v.reseller, NULL, NULL
             FROM reseller_payments p JOIN resellers r ON r.id = p.reseller_id
             CROSS JOIN LATERAL (VALUES
                (CASE WHEN p.method = 'cost_offset' THEN 'offset_clearing' ELSE 'cash' END, p.cash_account_id, p.amount, CASE WHEN p.method = 'cost_offset' THEN p.reseller_id END),
                ('ar_reseller', NULL::bigint, -p.amount, p.reseller_id)) v(role, cash, amount, reseller)",

            // Biaya operasional: akun kategori / kas (atau penampung bila dibayar reseller).
            "SELECT NULL, e.expense_date, 'expense', 'expense:' || e.id, c.name || COALESCE(' - ' || e.note, ''), v.role, v.cash, v.account, v.amount, v.reseller, NULL, NULL
             FROM expenses e JOIN expense_categories c ON c.id = e.category_id LEFT JOIN reseller_payments rp ON rp.id = e.reseller_payment_id
             CROSS JOIN LATERAL (VALUES
                (CASE WHEN c.account_id IS NULL THEN 'expense_default' END, NULL::bigint, c.account_id, e.amount, NULL::bigint),
                (CASE WHEN rp.id IS NULL THEN 'cash' ELSE 'offset_clearing' END, e.cash_account_id, NULL::bigint, -e.amount, rp.reseller_id)) v(role, cash, account, amount, reseller)",

            // Modal & prive.
            "SELECT NULL, q.transacted_at, 'equity', 'equity:' || q.id, CASE WHEN q.type = 'setoran' THEN 'Setoran modal' ELSE 'Prive' END || COALESCE(' - ' || q.note, ''),
                    v.role, v.cash, NULL, v.amount, NULL, NULL, NULL
             FROM equity_transactions q CROSS JOIN LATERAL (VALUES
                ('cash', q.cash_account_id, CASE WHEN q.type = 'setoran' THEN q.amount ELSE -q.amount END),
                (CASE WHEN q.type = 'setoran' THEN 'equity_capital' ELSE 'equity_drawing' END, NULL::bigint, CASE WHEN q.type = 'setoran' THEN -q.amount ELSE q.amount END)) v(role, cash, amount)",

            // Pembelian bahan: persediaan bahan / kas, utang supplier, atau penampung dibayar reseller.
            "SELECT NULL, p.purchase_date, 'material_purchase', 'purchase:' || p.id, 'Pembelian bahan' || COALESCE(' ' || s.name, '') || COALESCE(' (' || p.ref_no || ')', ''),
                    v.role, v.cash, NULL, v.amount, v.reseller, v.supplier, NULL
             FROM material_purchases p JOIN (SELECT purchase_id, SUM(total_price) AS total FROM material_purchase_items GROUP BY 1) t ON t.purchase_id = p.id
             LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN reseller_payments rp ON rp.id = p.reseller_payment_id
             CROSS JOIN LATERAL (VALUES
                ('inventory_material', NULL::bigint, t.total, NULL::bigint, NULL::bigint),
                (CASE p.payment_method WHEN 'credit' THEN 'ap_supplier' WHEN 'reseller' THEN 'offset_clearing' ELSE 'cash' END,
                 p.cash_account_id, -t.total, rp.reseller_id, CASE WHEN p.payment_method = 'credit' THEN p.supplier_id END)) v(role, cash, amount, reseller, supplier)",

            // Bahan: stok awal (saldo awal), penyesuaian & opname (selisih persediaan; opname awal = saldo awal).
            "SELECT NULL, d, 'material_stock', key, label, v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT mm.moved_at::date AS d,
                          CASE WHEN o.id IS NOT NULL THEN 'opname:' || o.id ELSE mm.movement_type END AS key,
                          CASE WHEN mm.movement_type = 'opening' THEN 'Stok awal bahan' WHEN o.id IS NOT NULL THEN 'Opname bahan' ELSE 'Penyesuaian bahan' END AS label,
                          CASE WHEN mm.movement_type = 'opening' OR o.is_opening THEN 'opening_equity' ELSE 'inventory_variance' END AS counter,
                          SUM(ROUND(mm.qty * ".sprintf($materialCost, 'mm').", 2)) AS value
                   FROM material_movements mm
                   LEFT JOIN material_opname_items oi ON mm.source_type = 'material_opname_item' AND oi.id = mm.source_id
                   LEFT JOIN material_opnames o ON o.id = oi.opname_id
                   WHERE mm.movement_type IN ('opening', 'adjustment', 'opname') GROUP BY 1, 2, 3, 4) x
             CROSS JOIN LATERAL (VALUES ('inventory_material', value), (counter, -value)) v(role, amount)",

            // SPK: bahan terpakai saat potong (BDP / bahan).
            "SELECT NULL, d, 'work_order', 'wo:' || wo_id, 'SPK ' || code || ' - bahan dipotong', v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT mm.moved_at::date AS d, w.id AS wo_id, w.code, SUM(ROUND(-mm.qty * ".sprintf($materialCost, 'mm').", 2)) AS value
                   FROM material_movements mm JOIN work_order_items wi ON mm.source_type = 'work_order_item' AND wi.id = mm.source_id
                   JOIN work_orders w ON w.id = wi.work_order_id
                   WHERE mm.movement_type = 'production_out' GROUP BY 1, 2, 3) x
             CROSS JOIN LATERAL (VALUES ('wip', value), ('inventory_material', -value)) v(role, amount)",

            // SPK: upah potong (saat potong) & upah jahit (saat selesai) -> BDP / utang upah per orang.
            "SELECT NULL, w.cut_date, 'work_order', 'wo:' || w.id, 'SPK ' || w.code || ' - upah potong', v.role, NULL, NULL, v.amount, NULL, NULL, v.tailor
             FROM work_orders w JOIN (SELECT work_order_id, SUM(ROUND(qty_cut * COALESCE(cutting_cost_snapshot, 0), 2)) AS wage FROM work_order_items GROUP BY 1) i ON i.work_order_id = w.id
             CROSS JOIN LATERAL (VALUES ('wip', i.wage, NULL::bigint), ('wages_payable', -i.wage, w.cutter_id)) v(role, amount, tailor)
             WHERE w.status <> 'cancelled'",
            "SELECT NULL, w.finished_date, 'work_order', 'wo:' || w.id, 'SPK ' || w.code || ' - upah jahit', v.role, NULL, NULL, v.amount, NULL, NULL, v.tailor
             FROM work_orders w JOIN (SELECT work_order_id, SUM(ROUND(qty_good * COALESCE(sewing_cost_snapshot, 0), 2)) AS wage FROM work_order_items GROUP BY 1) i ON i.work_order_id = w.id
             CROSS JOIN LATERAL (VALUES ('wip', i.wage, NULL::bigint), ('wages_payable', -i.wage, w.tailor_id)) v(role, amount, tailor)
             WHERE w.status = 'done'",

            // SPK selesai: barang jadi masuk (harga bahan + ongkos), sisa BDP (reject) -> selisih produksi.
            // SPK batal: bahan yang sudah dipotong dibebankan ke selisih produksi.
            "SELECT NULL, d, 'work_order', 'wo:' || wo_id, 'SPK ' || code || CASE WHEN done THEN ' - selesai' ELSE ' - batal' END, v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT w.id AS wo_id, w.code, w.status = 'done' AS done,
                          CASE WHEN w.status = 'done' THEN w.finished_date ELSE (w.updated_at AT TIME ZONE 'Asia/Jakarta')::date END AS d,
                          COALESCE(mat.value, 0) + CASE WHEN w.status = 'done' THEN COALESCE(wage.value, 0) ELSE 0 END AS wip,
                          COALESCE(fg.value, 0) AS fg
                   FROM work_orders w
                   LEFT JOIN LATERAL (SELECT SUM(ROUND(-mm.qty * ".sprintf($materialCost, 'mm').", 2)) AS value
                        FROM material_movements mm JOIN work_order_items wi ON mm.source_type = 'work_order_item' AND wi.id = mm.source_id
                        WHERE wi.work_order_id = w.id AND mm.movement_type = 'production_out') mat ON true
                   LEFT JOIN LATERAL (SELECT SUM(ROUND(qty_cut * COALESCE(cutting_cost_snapshot, 0), 2)) + SUM(ROUND(qty_good * COALESCE(sewing_cost_snapshot, 0), 2)) AS value
                        FROM work_order_items WHERE work_order_id = w.id) wage ON true
                   LEFT JOIN LATERAL (SELECT SUM(ROUND(sm.qty * COALESCE(sm.unit_cost, 0), 2)) AS value
                        FROM stock_movements sm JOIN work_order_items wi ON sm.source_type = 'work_order_item' AND wi.id = sm.source_id
                        WHERE wi.work_order_id = w.id AND sm.movement_type = 'production_in') fg ON true
                   WHERE w.status IN ('done', 'cancelled')) x
             CROSS JOIN LATERAL (VALUES ('inventory_fg', fg), ('production_variance', wip - fg), ('wip', -wip)) v(role, amount)",

            // Pembayaran upah (per orang atau gabungan): utang upah / kas atau penampung dibayar reseller.
            "SELECT NULL, t.paid_at, 'wage', 'wage:' || t.id, 'Upah ' || CASE WHEN t.wage_type = 'potong' THEN 'potong' ELSE 'jahit' END || ' ' || COALESCE(tl.name, 'gabungan') || COALESCE(' - ' || t.note, ''),
                    v.role, v.cash, NULL, v.amount, v.reseller, NULL, v.tailor
             FROM tailor_payments t LEFT JOIN tailors tl ON tl.id = t.tailor_id LEFT JOIN reseller_payments rp ON rp.id = t.reseller_payment_id
             CROSS JOIN LATERAL (VALUES
                ('wages_payable', NULL::bigint, t.amount, NULL::bigint, t.tailor_id),
                (CASE WHEN rp.id IS NULL THEN 'cash' ELSE 'offset_clearing' END, t.cash_account_id, -t.amount, rp.reseller_id, NULL::bigint)) v(role, cash, amount, reseller, tailor)",

            // Barang masuk: borongan / beli jadi tunai (persediaan / kas); produksi luar SPK, retur & lainnya (persediaan / selisih).
            "SELECT NULL, (r.received_at AT TIME ZONE 'Asia/Jakarta')::date, 'goods_receipt', 'receipt:' || r.id, 'Barang masuk ' || r.receipt_no, v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM goods_receipts r
             JOIN LATERAL (SELECT SUM(ROUND(i.qty * COALESCE(i.unit_cost, CASE WHEN r.source IN ('produksi', 'retur', 'lainnya') THEN ".sprintf($variantCost, 'i.variant_id', '(r.received_at)::date')." END, 0), 2)) AS value
                           FROM goods_receipt_items i WHERE i.receipt_id = r.id) t ON true
             CROSS JOIN LATERAL (VALUES ('inventory_fg', t.value),
                (CASE WHEN r.source IN ('borongan', 'beli_jadi') THEN 'cash_unassigned' ELSE 'inventory_variance' END, -t.value)) v(role, amount)
             WHERE r.source IN ('borongan', 'produksi', 'retur', 'lainnya') OR (r.source = 'beli_jadi' AND r.supplier_id IS NULL)",

            // Tagihan supplier beli jadi (pembelian & barang keluar/dropship; negatif = retur): persediaan / utang supplier.
            "SELECT NULL, b.billed_on, 'supplier_bill', 'supplier:' || b.supplier_id, 'Tagihan ' || s.name, v.role, NULL, NULL, v.amount, NULL, v.supplier, NULL
             FROM (SELECT billed_on, supplier_id, SUM(amount) AS amount FROM supplier_bill_lines WHERE supplier_id IS NOT NULL AND amount IS NOT NULL GROUP BY 1, 2) b
             JOIN suppliers s ON s.id = b.supplier_id
             CROSS JOIN LATERAL (VALUES ('inventory_fg', b.amount, NULL::bigint), ('ap_supplier', -b.amount, b.supplier_id)) v(role, amount, supplier)",
            "SELECT NULL, p.paid_at, 'supplier_payment', 'supplier_payment:' || p.id, 'Pembayaran ' || s.name || COALESCE(' - ' || p.note, ''), v.role, v.cash, NULL, v.amount, NULL, v.supplier, NULL
             FROM supplier_payments p JOIN suppliers s ON s.id = p.supplier_id
             CROSS JOIN LATERAL (VALUES ('ap_supplier', NULL::bigint, p.amount, p.supplier_id), ('cash', p.cash_account_id, -p.amount, NULL::bigint)) v(role, cash, amount, supplier)",

            // Barang jadi: stok awal (saldo awal), penyesuaian & opname (selisih persediaan; opname awal = saldo awal).
            "SELECT NULL, d, 'stock', key, label, v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT (sm.moved_at AT TIME ZONE 'Asia/Jakarta')::date AS d,
                          CASE WHEN o.id IS NOT NULL THEN 'opname:' || o.id ELSE sm.movement_type END AS key,
                          CASE WHEN sm.movement_type = 'opening' THEN 'Stok awal barang jadi' WHEN o.id IS NOT NULL THEN 'Opname barang jadi' ELSE 'Penyesuaian stok barang jadi' END AS label,
                          CASE WHEN sm.movement_type = 'opening' OR o.is_opening THEN 'opening_equity' ELSE 'inventory_variance' END AS counter,
                          SUM(ROUND(sm.qty * COALESCE(sm.unit_cost, ".sprintf($variantCost, 'sm.variant_id', '(sm.moved_at)::date').", 0), 2)) AS value
                   FROM stock_movements sm
                   LEFT JOIN stock_opname_items oi ON sm.source_type = 'stock_opname_item' AND oi.id = sm.source_id
                   LEFT JOIN stock_opnames o ON o.id = oi.opname_id
                   WHERE sm.movement_type IN ('opening', 'adjustment', 'opname') GROUP BY 1, 2, 3, 4) x
             CROSS JOIN LATERAL (VALUES ('inventory_fg', value), (counter, -value)) v(role, amount)",

            // TOKO per hari (komponen sama dengan laba rugi toko): omzet - refund, fee admin, iklan aktual,
            // harga konveksi; tagihan yang belum punya data pendapatan = menunggu data pendapatan.
            "SELECT store_id, d, 'store_day', 'day', 'Penjualan & biaya toko', v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT store_id, d, SUM(r) AS r, SUM(c) AS c, SUM(f) AS f, SUM(b) AS b, SUM(a) AS a FROM (
                      SELECT so.store_id, so.order_date AS d, li.revenue AS r, li.cost AS c,
                             COALESCE(so.platform_fee, COALESCE(li.revenue, 0) * COALESCE(fr.admin_fee_pct, 0)) AS f, li.billed AS b, 0 AS a
                      FROM sales_orders so JOIN stores st ON st.id = so.store_id
                      CROSS JOIN LATERAL (SELECT COALESCE(SUM(i.revenue), 0) AS revenue, COALESCE(SUM(i.bill_amount) FILTER (WHERE i.revenue IS NOT NULL), 0) AS cost,
                                                 SUM(i.bill_amount) AS billed FROM sales_order_items i WHERE i.sales_order_id = so.id) li
                      LEFT JOIN LATERAL (SELECT r.admin_fee_pct FROM channel_fee_rates r WHERE r.channel_id = st.channel_id AND r.effective_from <= so.order_date
                                         ORDER BY r.effective_from DESC LIMIT 1) fr ON true
                      WHERE so.status <> 'cancelled'
                      UNION ALL
                      SELECT so.store_id, sr.returned_at,
                             CASE WHEN so.status <> 'cancelled' THEN -COALESCE(sr.refund_amount, i.revenue * sr.qty / i.qty, 0) ELSE 0 END,
                             CASE WHEN so.status <> 'cancelled' AND sr.credit_reseller AND i.revenue IS NOT NULL THEN -sr.qty * i.reseller_unit_price ELSE 0 END,
                             0, CASE WHEN sr.credit_reseller THEN -sr.qty * i.reseller_unit_price ELSE 0 END, 0
                      FROM sales_returns sr JOIN sales_order_items i ON i.id = sr.sales_order_item_id JOIN sales_orders so ON so.id = i.sales_order_id
                      UNION ALL
                      SELECT store_id, spend_date, 0, 0, 0, 0, amount FROM store_ad_spends
                   ) x GROUP BY 1, 2) t
             CROSS JOIN LATERAL (VALUES
                ('store_cash', ROUND(r, 2) - ROUND(f, 2) - ROUND(a, 2)), ('store_sales', -ROUND(r, 2)), ('store_admin_fee', ROUND(f, 2)), ('store_ads', ROUND(a, 2)),
                ('store_cogs', c), ('store_pending', b - c), ('store_payable', -b)) v(role, amount)",

            // TOKO: estimasi iklan (% channel) untuk bulan tanpa data iklan aktual, di akhir bulan.
            "SELECT store_id, LEAST((month + INTERVAL '1 month' - INTERVAL '1 day')::date, {$today}), 'store_ads', to_char(month, 'YYYY-MM'),
                    'Estimasi iklan ' || to_char(month, 'MM/YYYY'), v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT store_id, month, ads FROM fn_store_pl({$today}) WHERE ads_estimated AND ads > 0) e
             CROSS JOIN LATERAL (VALUES ('store_ads', ROUND(ads, 2)), ('store_cash', -ROUND(ads, 2))) v(role, amount)",

            // TOKO: pembayaran reseller ke konveksi dibagi ke toko-tokonya sebanding tagihan s.d. tanggal bayar.
            "SELECT store_id, d, 'payment', 'payment:' || pid, 'Pembayaran ke konveksi', v.role, NULL, NULL, v.amount, NULL, NULL, NULL
             FROM (SELECT *, share + CASE WHEN rn = 1 THEN amount - SUM(share) OVER (PARTITION BY pid) ELSE 0 END AS alloc
                   FROM (SELECT *, CASE WHEN tw > 0 THEN ROUND(amount * w / tw, 2) WHEN rn = 1 THEN amount ELSE 0 END AS share
                         FROM (SELECT *, SUM(w) OVER (PARTITION BY pid) AS tw, ROW_NUMBER() OVER (PARTITION BY pid ORDER BY w DESC, store_id) AS rn
                               FROM (SELECT p.id AS pid, p.amount, (p.paid_at AT TIME ZONE 'Asia/Jakarta')::date AS d, s.id AS store_id,
                                            COALESCE((SELECT SUM(bill_amount) FROM v_sales_lines l WHERE l.store_id = s.id AND l.status <> 'cancelled'
                                                      AND l.order_date <= (p.paid_at AT TIME ZONE 'Asia/Jakarta')::date), 0) AS w
                                     FROM reseller_payments p JOIN stores s ON s.reseller_id = p.reseller_id) a) b) c) f
             CROSS JOIN LATERAL (VALUES ('store_payable', alloc), ('store_cash', -alloc)) v(role, amount)
             WHERE alloc <> 0",
        ];
    }

    /**
     * Isi account_id dari role (kas: akun rekening, tanpa rekening = kas belum ditentukan).
     */
    private function resolveAccounts(): void
    {
        DB::update(<<<'SQL'
            UPDATE tmp_postings t SET account_id = COALESCE(
                (SELECT a.id FROM accounts a WHERE a.cash_account_id = t.cash_account_id),
                (SELECT a.id FROM accounts a WHERE a.ledger = 'konveksi' AND a.role = 'cash_unassigned'))
            WHERE t.account_id IS NULL AND t.role = 'cash'
            SQL);

        DB::update(<<<'SQL'
            UPDATE tmp_postings t SET account_id = a.id FROM accounts a
            WHERE t.account_id IS NULL AND a.role = t.role AND a.ledger = CASE WHEN t.store_id IS NULL THEN 'konveksi' ELSE 'toko' END
            SQL);

        $missing = DB::table('tmp_postings')->whereNull('account_id')->distinct()->pluck('role')->all();

        if ($missing !== []) {
            throw new BusinessRuleException('Akun dengan peran berikut tidak ada di bagan akun: '.implode(', ', $missing));
        }
    }

    /**
     * Estimasi pajak per bulan (konveksi & tiap toko): tarif x laba sebelum pajak kumulatif tahun berjalan,
     * dikurangi pajak bulan-bulan sebelumnya pada tahun yang sama (rugi mengurangi kembali).
     */
    private function postTax(): void
    {
        if (! FinancialStatements::taxEnabled()) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO tmp_postings (store_id, entry_date, source_type, source_key, description, role, amount)
            SELECT store_id, LEAST((month + INTERVAL '1 month' - INTERVAL '1 day')::date, ?::date), 'tax', to_char(month, 'YYYY-MM'),
                   'Estimasi pajak penghasilan ' || to_char(month, 'MM/YYYY'), v.role, v.amount
            FROM (SELECT store_id, month, tax - COALESCE(LAG(tax) OVER (PARTITION BY store_id, date_trunc('year', month) ORDER BY month), 0) AS delta
                  FROM (SELECT store_id, month, ROUND(GREATEST(SUM(profit) OVER (PARTITION BY store_id, date_trunc('year', month) ORDER BY month), 0) * ?, 2) AS tax
                        FROM (SELECT t.store_id, date_trunc('month', t.entry_date)::date AS month, -SUM(t.amount) AS profit
                              FROM tmp_postings t JOIN accounts a ON a.id = t.account_id
                              WHERE a.type IN ('revenue', 'cogs', 'expense', 'other_income', 'other_expense')
                              GROUP BY 1, 2) m) y) d
            CROSS JOIN LATERAL (VALUES
                (CASE WHEN store_id IS NULL THEN 'tax_expense' ELSE 'store_tax_expense' END, delta),
                (CASE WHEN store_id IS NULL THEN 'tax_payable' ELSE 'store_tax_payable' END, -delta)) v(role, amount)
            WHERE delta <> 0
            SQL, [today()->toDateString(), FinancialStatements::taxRate()]);
    }

    /**
     * Selisih antara transaksi sekarang dan jurnal yang sudah dikunci (periode ditutup) per akun.
     *
     * @return list<array{account: string, expected: float, posted: float}>
     */
    private function drift(string $closedUntil): array
    {
        if ($closedUntil === '1900-01-01') {
            return [];
        }

        return array_map(fn ($row): array => ['account' => $row->account, 'expected' => (float) $row->expected, 'posted' => (float) $row->posted], DB::select(<<<'SQL'
            SELECT a.code || ' ' || a.name || CASE WHEN a.ledger = 'toko' THEN ' (toko)' ELSE '' END AS account,
                   COALESCE(x.expected, 0) AS expected, COALESCE(y.posted, 0) AS posted
            FROM (SELECT account_id, SUM(amount) AS expected FROM tmp_postings WHERE entry_date <= ? GROUP BY 1) x
            FULL JOIN (SELECT l.account_id, SUM(l.debit - l.credit) AS posted FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                       WHERE e.is_auto AND e.entry_date <= ? GROUP BY 1) y ON y.account_id = x.account_id
            JOIN accounts a ON a.id = COALESCE(x.account_id, y.account_id)
            WHERE COALESCE(x.expected, 0) <> COALESCE(y.posted, 0)
            ORDER BY a.ledger, a.code
            SQL, [$closedUntil, $closedUntil]));
    }

    // ------------------------------------------------------------------ TUTUP BUKU

    public function closedUntil(): ?Carbon
    {
        $month = LedgerClosing::query()->max('month');

        return $month ? Carbon::parse($month)->endOfMonth()->startOfDay() : null;
    }

    public function isClosed(CarbonInterface|string $date): bool
    {
        $closedUntil = $this->closedUntil();

        return $closedUntil !== null && Carbon::parse($date)->startOfDay()->lte($closedUntil);
    }

    public function ensureOpen(CarbonInterface|string|null $date, string $what = 'Transaksi'): void
    {
        if ($date !== null && $this->isClosed($date)) {
            throw new BusinessRuleException("{$what} tanggal ".Carbon::parse($date)->format('d/m/Y').' berada di periode yang sudah ditutup buku. Buka kembali periodenya dulu.');
        }
    }

    /**
     * Tutup buku s.d. akhir bulan (semua bulan sebelumnya ikut ditutup).
     */
    public function close(Carbon $month): void
    {
        $this->sync();
        $month = $month->copy()->startOfMonth();
        $first = DB::scalar('SELECT MIN(entry_date) FROM journal_entries');

        if ($month->copy()->endOfMonth()->gte(today())) {
            throw new BusinessRuleException('Hanya bulan yang sudah berakhir yang bisa ditutup.');
        }

        for ($current = Carbon::parse($first ?? $month)->startOfMonth(); $current->lte($month); $current->addMonth()) {
            LedgerClosing::query()->firstOrCreate(['month' => $current->toDateString()], ['closed_by' => auth()->id(), 'closed_at' => now()]);
        }
    }

    /**
     * Buka kembali bulan ini dan semua bulan sesudahnya, lalu susun ulang jurnalnya.
     */
    public function reopen(Carbon $month): void
    {
        LedgerClosing::query()->where('month', '>=', $month->copy()->startOfMonth()->toDateString())->delete();
        $this->sync();
    }

    // ------------------------------------------------------------------ MEMBACA BUKU BESAR

    /**
     * @return array<string, string>
     */
    public static function entities(): array
    {
        return FinancialStatements::entities();
    }

    public static function ledgerOf(string $entity): string
    {
        return $entity === self::KONVEKSI ? ChartOfAccounts::KONVEKSI : ChartOfAccounts::STORE;
    }

    /**
     * Saldo per akun (debit, kredit, saldo = debit - kredit) untuk entitas & rentang tanggal (from null = sejak awal).
     *
     * @param  list<string>  $types
     * @return Collection<int, object>
     */
    public function balances(string $entity, ?CarbonInterface $from, CarbonInterface $until, array $types = []): Collection
    {
        $this->ensureFresh();

        $query = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.entry_date', '<=', $until->toDateString())
            ->when($from, fn ($query) => $query->where('l.entry_date', '>=', $from->toDateString()))
            ->when($types !== [], fn ($query) => $query->whereIn('a.type', $types))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role', 'a.normal_balance', 'a.cash_account_id', 'a.cash_flow')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, a.role, a.normal_balance, a.cash_account_id, a.cash_flow, SUM(l.debit) AS debit, SUM(l.credit) AS credit, SUM(l.debit - l.credit) AS net');

        $this->scope($query, $entity);

        return $query->get()->map(function (object $row): object {
            $row->debit = round((float) $row->debit, 2);
            $row->credit = round((float) $row->credit, 2);
            $row->net = round((float) $row->net, 2);

            return $row;
        });
    }

    /**
     * Saldo akun ber-role per dimensi (reseller / supplier / penjahit), mis. untuk memisah piutang & deposit.
     *
     * @return list<float>
     */
    public function balancesBy(string $entity, string $role, string $dimension, CarbonInterface $until): array
    {
        $this->ensureFresh();

        $query = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.role', $role)
            ->where('l.entry_date', '<=', $until->toDateString())
            ->groupBy("l.{$dimension}")
            ->selectRaw('SUM(l.debit - l.credit) AS net');

        $this->scope($query, $entity);

        return $query->pluck('net')->map(fn ($net): float => round((float) $net, 2))->all();
    }

    /**
     * Batasi query journal_lines (alias l) ke entitas.
     */
    public function scope($query, string $entity, string $alias = 'l'): void
    {
        match (true) {
            $entity === self::KONVEKSI => $query->whereNull("{$alias}.store_id"),
            $entity === self::ALL_STORES => $query->whereNotNull("{$alias}.store_id"),
            str_starts_with($entity, 'store:') => $query->where("{$alias}.store_id", (int) substr($entity, 6)),
            default => $query->whereRaw('false'),
        };
    }
}
