<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fungsi & view laporan. Dibaca lewat query builder / model read-only.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- harga dasar berlaku per tanggal (dipakai saat simpan penjualan untuk isi snapshot)
            CREATE OR REPLACE FUNCTION fn_base_price(p_variant bigint, p_date date)
            RETURNS TABLE (price_id bigint, base_price numeric) LANGUAGE sql STABLE AS $$
              SELECT id, base_price FROM reseller_price_histories
              WHERE variant_id = p_variant AND effective_from <= p_date
              ORDER BY effective_from DESC LIMIT 1
            $$;

            -- % tier pemilik per tanggal
            CREATE OR REPLACE FUNCTION fn_owner_tier_pct(p_owner bigint, p_date date)
            RETURNS numeric LANGUAGE sql STABLE AS $$
              SELECT COALESCE((SELECT t.adjustment_pct
                               FROM owner_tier_histories h JOIN price_tiers t ON t.id = h.price_tier_id
                               WHERE h.owner_id = p_owner AND h.effective_from <= p_date
                               ORDER BY h.effective_from DESC LIMIT 1), 0)
            $$;

            -- % fee channel yang berlaku sekarang
            CREATE OR REPLACE VIEW v_channel_fee_current AS
            SELECT DISTINCT ON (channel_id) channel_id, admin_fee_pct, ads_pct, effective_from
            FROM channel_fee_rates WHERE effective_from <= current_date
            ORDER BY channel_id, effective_from DESC;

            -- HPP & harga berlaku sekarang + usulan harga baru (UPDATE HARGA)
            CREATE OR REPLACE VIEW v_variant_pricing AS
            WITH c AS (SELECT DISTINCT ON (variant_id) variant_id, total_cost FROM variant_costs
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 p AS (SELECT DISTINCT ON (variant_id) variant_id, base_price FROM reseller_price_histories
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 s AS (SELECT (SELECT (value #>> '{}')::numeric FROM settings WHERE key = 'margin_default') AS margin_default,
                              (SELECT (value #>> '{}')::numeric FROM settings WHERE key = 'price_rounding') AS rounding)
            SELECT v.id AS variant_id, v.sku, c.total_cost AS hpp,
                   COALESCE(v.margin_pct, m.default_margin_pct, s.margin_default, 0.30) AS margin_pct,
                   p.base_price AS current_price,
                   CEIL(c.total_cost * (1 + COALESCE(v.margin_pct, m.default_margin_pct, s.margin_default, 0.30))
                        / COALESCE(NULLIF(s.rounding, 0), 100)) * COALESCE(NULLIF(s.rounding, 0), 100) AS proposed_price
            FROM product_variants v
            JOIN product_models m ON m.id = v.model_id
            LEFT JOIN c ON c.variant_id = v.id
            LEFT JOIN p ON p.variant_id = v.id
            CROSS JOIN s
            WHERE v.deleted_at IS NULL;

            -- baris penjualan lengkap (join sekali, dipakai semua laporan)
            CREATE OR REPLACE VIEW v_sales_lines AS
            SELECT i.id, o.id AS sales_order_id, o.order_date, o.status, o.store_id, st.reseller_id, r.owner_id, st.channel_id,
                   o.warehouse_id, i.variant_id, i.qty, i.revenue, i.reseller_unit_price, i.bill_amount, i.margin,
                   i.hpp_unit, i.qty * i.hpp_unit AS hpp_amount, i.reseller_invoice_id, o.platform_fee
            FROM sales_order_items i
            JOIN sales_orders o ON o.id = i.sales_order_id
            JOIN stores st ON st.id = o.store_id
            JOIN resellers r ON r.id = st.reseller_id;

            -- LABA RUGI KONVEKSI per bulan
            CREATE OR REPLACE VIEW v_konveksi_monthly_pl AS
            WITH s AS (SELECT date_trunc('month', order_date)::date AS month,
                              SUM(bill_amount) AS sales_to_resellers, SUM(hpp_amount) AS hpp
                       FROM v_sales_lines WHERE status <> 'cancelled' GROUP BY 1),
                 rt AS (SELECT date_trunc('month', sr.returned_at)::date AS month,
                               SUM(sr.qty * i.reseller_unit_price) AS returns_credit, SUM(sr.qty * i.hpp_unit) AS returns_hpp
                        FROM sales_returns sr JOIN sales_order_items i ON i.id = sr.sales_order_item_id
                        WHERE sr.credit_reseller GROUP BY 1),
                 e AS (SELECT date_trunc('month', expense_date)::date AS month, SUM(amount) AS expenses FROM expenses GROUP BY 1),
                 m AS (SELECT month FROM s UNION SELECT month FROM rt UNION SELECT month FROM e)
            SELECT m.month,
                   COALESCE(s.sales_to_resellers, 0) - COALESCE(rt.returns_credit, 0) AS net_sales,
                   COALESCE(s.hpp, 0) - COALESCE(rt.returns_hpp, 0) AS hpp,
                   COALESCE(s.sales_to_resellers, 0) - COALESCE(rt.returns_credit, 0)
                     - COALESCE(s.hpp, 0) + COALESCE(rt.returns_hpp, 0) AS gross_profit,
                   COALESCE(e.expenses, 0) AS expenses,
                   COALESCE(s.sales_to_resellers, 0) - COALESCE(rt.returns_credit, 0)
                     - COALESCE(s.hpp, 0) + COALESCE(rt.returns_hpp, 0) - COALESCE(e.expenses, 0) AS net_profit
            FROM m LEFT JOIN s USING (month) LEFT JOIN rt USING (month) LEFT JOIN e USING (month);

            -- LABA RUGI TOKO per toko per bulan.
            -- Fee admin dihitung PER PESANAN: platform_fee aktual bila ada, selain itu estimasi
            -- % channel yang berlaku PADA TANGGAL PESANAN. Iklan: aktual (store_ad_spends) bila ada,
            -- selain itu estimasi % iklan per pesanan. Kolom *_estimated menandai angka estimasi.
            CREATE OR REPLACE VIEW v_store_monthly_pl AS
            WITH o AS (
                SELECT so.store_id, date_trunc('month', so.order_date)::date AS month, so.platform_fee,
                       li.revenue, li.cost_of_goods, li.gross_profit,
                       COALESCE(f.admin_fee_pct, 0) AS admin_fee_pct, COALESCE(f.ads_pct, 0) AS ads_pct
                FROM sales_orders so
                JOIN stores st ON st.id = so.store_id
                CROSS JOIN LATERAL (
                    SELECT SUM(i.revenue) AS revenue,
                           SUM(i.bill_amount) FILTER (WHERE i.revenue IS NOT NULL) AS cost_of_goods,
                           SUM(i.margin) AS gross_profit
                    FROM sales_order_items i WHERE i.sales_order_id = so.id) li
                LEFT JOIN LATERAL (
                    SELECT r.admin_fee_pct, r.ads_pct FROM channel_fee_rates r
                    WHERE r.channel_id = st.channel_id AND r.effective_from <= so.order_date
                    ORDER BY r.effective_from DESC LIMIT 1) f ON true
                WHERE so.status NOT IN ('cancelled', 'returned')),
            o2 AS (
                SELECT o.*, COALESCE(o.platform_fee, COALESCE(o.revenue, 0) * o.admin_fee_pct) AS admin_fee
                FROM o),
            l AS (
                SELECT store_id, month,
                       SUM(revenue) AS revenue, SUM(cost_of_goods) AS cost_of_goods, SUM(gross_profit) AS gross_profit,
                       SUM(admin_fee) AS admin_fee,
                       bool_or(platform_fee IS NULL AND admin_fee_pct > 0) AS admin_fee_estimated,
                       SUM((COALESCE(gross_profit, 0) - admin_fee) * ads_pct) AS ads_estimate
                FROM o2 GROUP BY 1, 2),
            ad AS (
                SELECT store_id, date_trunc('month', spend_date)::date AS month, SUM(amount) AS ads_actual
                FROM store_ad_spends GROUP BY 1, 2)
            SELECT l.store_id || '-' || to_char(l.month, 'YYYY-MM') AS id,
                   l.store_id, l.month, l.revenue, l.cost_of_goods, l.gross_profit,
                   l.admin_fee, l.admin_fee_estimated,
                   COALESCE(ad.ads_actual, GREATEST(l.ads_estimate, 0)) AS ads,
                   ad.ads_actual IS NULL AS ads_estimated,
                   COALESCE(l.gross_profit, 0) - l.admin_fee - COALESCE(ad.ads_actual, GREATEST(l.ads_estimate, 0)) AS net_profit
            FROM l
            LEFT JOIN ad ON ad.store_id = l.store_id AND ad.month = l.month;

            -- PIUTANG per reseller
            CREATE OR REPLACE VIEW v_reseller_ar AS
            SELECT r.id AS reseller_id, r.owner_id, r.name, r.opening_balance,
                   COALESCE(b.billed, 0) AS billed,
                   COALESCE(rt.credit, 0) AS returns_credit,
                   COALESCE(p.paid, 0) AS paid,
                   r.opening_balance + COALESCE(b.billed, 0) - COALESCE(rt.credit, 0) - COALESCE(p.paid, 0) AS outstanding
            FROM resellers r
            LEFT JOIN (SELECT reseller_id, SUM(bill_amount) AS billed FROM v_sales_lines
                       WHERE status <> 'cancelled' GROUP BY 1) b ON b.reseller_id = r.id
            LEFT JOIN (SELECT st.reseller_id, SUM(sr.qty * i.reseller_unit_price) AS credit
                       FROM sales_returns sr
                       JOIN sales_order_items i ON i.id = sr.sales_order_item_id
                       JOIN sales_orders o ON o.id = i.sales_order_id
                       JOIN stores st ON st.id = o.store_id
                       WHERE sr.credit_reseller GROUP BY 1) rt ON rt.reseller_id = r.id
            LEFT JOIN (SELECT reseller_id, SUM(amount) AS paid FROM reseller_payments GROUP BY 1) p ON p.reseller_id = r.id;

            -- DASHBOARD: terlaris & prioritas produksi
            CREATE OR REPLACE FUNCTION fn_production_priority(p_days int DEFAULT 30, p_target_days int DEFAULT 30,
                                                              p_lead_days int DEFAULT 7, p_batch int DEFAULT 1,
                                                              p_ref date DEFAULT current_date)
            RETURNS TABLE (variant_id bigint, sku varchar, model varchar, sold bigint, per_day numeric, stock bigint,
                           days_of_stock numeric, suggested_qty integer, priority text)
            LANGUAGE sql STABLE AS $$
              WITH sold AS (
                SELECT l.variant_id, SUM(l.qty) AS sold FROM v_sales_lines l
                WHERE l.status NOT IN ('cancelled', 'returned') AND l.order_date > p_ref - p_days AND l.order_date <= p_ref
                GROUP BY 1),
              st AS (SELECT b.variant_id, SUM(b.qty) AS stock FROM stock_balances b GROUP BY 1),
              x AS (
                SELECT v.id, v.sku, m.name AS model, COALESCE(s.sold, 0) AS sold,
                       COALESCE(s.sold, 0)::numeric / p_days AS per_day, COALESCE(st.stock, 0) AS stock
                FROM product_variants v
                JOIN product_models m ON m.id = v.model_id AND m.kind = 'produksi'
                LEFT JOIN sold s ON s.variant_id = v.id
                LEFT JOIN st ON st.variant_id = v.id
                WHERE v.is_active AND v.deleted_at IS NULL)
              SELECT id, sku, model, sold, round(per_day, 2), stock,
                     CASE WHEN per_day > 0 THEN round(GREATEST(stock, 0) / per_day, 1) END,
                     CASE WHEN per_day > 0
                          THEN (CEIL(GREATEST(per_day * (p_target_days + p_lead_days) - stock, 0) / p_batch) * p_batch)::int
                          ELSE 0 END,
                     CASE WHEN per_day = 0 THEN '-'
                          WHEN stock <= 0 THEN '1 HABIS'
                          WHEN stock / per_day <= p_lead_days THEN '2 SEGERA'
                          WHEN stock / per_day <= p_target_days + p_lead_days THEN '3 PERLU'
                          ELSE 'AMAN' END
              FROM x
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fn_production_priority(int, int, int, int, date);
            DROP VIEW IF EXISTS v_reseller_ar;
            DROP VIEW IF EXISTS v_store_monthly_pl;
            DROP VIEW IF EXISTS v_konveksi_monthly_pl;
            DROP VIEW IF EXISTS v_sales_lines;
            DROP VIEW IF EXISTS v_variant_pricing;
            DROP VIEW IF EXISTS v_channel_fee_current;
            DROP FUNCTION IF EXISTS fn_owner_tier_pct(bigint, date);
            DROP FUNCTION IF EXISTS fn_base_price(bigint, date);
            SQL);
    }
};
