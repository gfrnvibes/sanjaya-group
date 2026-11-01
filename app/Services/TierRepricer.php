<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * SINKRON TIER: harga reseller di baris penjualan disesuaikan dengan tier pemilik yang berlaku pada tanggal pesanan,
 * segera setelah riwayat tier pemilik atau % tier diubah.
 *
 * Yang disesuaikan: pesanan tidak batal, bukan titipan grosir, belum diretur, dan belum masuk tagihan TERBIT
 * (belum ditagih atau masih draft). Tagihan terbit dikunci dan tidak berubah.
 */
class TierRepricer
{
    /**
     * @param  list<int>|null  $ownerIds  null = semua pemilik
     * @return array{updated: int, locked: int}
     */
    public function reprice(?array $ownerIds = null, ?string $from = null): array
    {
        if ($ownerIds === []) {
            return ['updated' => 0, 'locked' => 0];
        }

        $rounding = (float) Setting::valueOf('tier_price_rounding', 100) ?: 1;
        [$scope, $bindings] = $this->scope($ownerIds, $from);

        return DB::transaction(function () use ($rounding, $scope, $bindings): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");

            $updated = DB::update(<<<SQL
                UPDATE sales_order_items si
                SET tier_adjustment_pct = x.pct, reseller_unit_price = x.price
                FROM (
                    SELECT i.id, t.pct, ROUND(rp.base_price * (1 + t.pct) / ?) * ? AS price
                    FROM sales_order_items i
                    JOIN sales_orders o ON o.id = i.sales_order_id
                    JOIN stores st ON st.id = o.store_id
                    JOIN resellers r ON r.id = st.reseller_id
                    JOIN reseller_price_histories rp ON rp.id = i.reseller_price_id
                    LEFT JOIN reseller_invoices inv ON inv.id = i.reseller_invoice_id
                    CROSS JOIN LATERAL (SELECT fn_owner_tier_pct(r.owner_id, o.order_date) AS pct) t
                    WHERE {$scope} AND (inv.id IS NULL OR inv.status = 'draft')
                ) x
                WHERE si.id = x.id AND (si.tier_adjustment_pct <> x.pct OR si.reseller_unit_price <> x.price)
                SQL, [$rounding, $rounding, ...$bindings]);

            // baris di tagihan terbit yang harganya berbeda dari tier sekarang (tidak diubah, hanya dilaporkan)
            $locked = (int) DB::scalar(<<<SQL
                SELECT COUNT(*)
                FROM sales_order_items i
                JOIN sales_orders o ON o.id = i.sales_order_id
                JOIN stores st ON st.id = o.store_id
                JOIN resellers r ON r.id = st.reseller_id
                JOIN reseller_invoices inv ON inv.id = i.reseller_invoice_id
                WHERE {$scope} AND inv.status <> 'draft' AND i.tier_adjustment_pct <> fn_owner_tier_pct(r.owner_id, o.order_date)
                SQL, $bindings);

            if ($updated > 0) {
                app(SalesDashboardService::class)->forget();
            }

            return ['updated' => $updated, 'locked' => $locked];
        });
    }

    /**
     * @param  list<int>|null  $ownerIds
     * @return array{0: string, 1: list<mixed>}
     */
    private function scope(?array $ownerIds, ?string $from): array
    {
        $conditions = ["o.status <> 'cancelled'", 'NOT i.is_consignment', 'NOT EXISTS (SELECT 1 FROM sales_returns sr WHERE sr.sales_order_item_id = i.id)'];
        $bindings = [];

        if ($ownerIds !== null) {
            $conditions[] = 'r.owner_id IN ('.implode(', ', array_fill(0, count($ownerIds), '?')).')';
            $bindings = array_map('intval', $ownerIds);
        }

        if ($from !== null) {
            $conditions[] = 'o.order_date >= ?';
            $bindings[] = $from;
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
