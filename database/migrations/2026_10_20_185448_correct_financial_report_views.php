<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_store_pl(p_as_of date)
            RETURNS TABLE (id text, store_id bigint, month date, revenue numeric, cost_of_goods numeric, gross_profit numeric,
                admin_fee numeric, admin_fee_estimated boolean, ads numeric, ads_estimated boolean, net_profit numeric)
            LANGUAGE sql STABLE AS $$
            WITH orders AS (
                SELECT so.store_id, date_trunc('month',so.order_date)::date AS month,
                    li.revenue, li.cost, li.revenue-li.cost AS gross,
                    COALESCE(so.platform_fee,COALESCE(li.revenue,0)*COALESCE(f.admin_fee_pct,0)) AS fee,
                    so.platform_fee IS NULL AND COALESCE(f.admin_fee_pct,0)>0 AS fee_estimated,
                    (COALESCE(li.revenue-li.cost,0)-COALESCE(so.platform_fee,COALESCE(li.revenue,0)*COALESCE(f.admin_fee_pct,0)))*COALESCE(f.ads_pct,0) AS ad_estimate
                FROM sales_orders so JOIN stores st ON st.id=so.store_id
                CROSS JOIN LATERAL (SELECT SUM(i.revenue) AS revenue,
                    SUM(i.bill_amount) FILTER(WHERE i.revenue IS NOT NULL) AS cost FROM sales_order_items i WHERE i.sales_order_id=so.id) li
                LEFT JOIN LATERAL (SELECT r.admin_fee_pct,r.ads_pct FROM channel_fee_rates r WHERE r.channel_id=st.channel_id AND r.effective_from<=so.order_date ORDER BY r.effective_from DESC LIMIT 1) f ON true
                WHERE so.status<>'cancelled' AND so.order_date<=p_as_of
            ), sold AS (
                SELECT store_id,month,SUM(revenue) AS revenue,SUM(cost) AS cost,SUM(fee) AS fee,bool_or(fee_estimated) AS estimated,SUM(ad_estimate) AS ad_estimate FROM orders GROUP BY 1,2
            ), returns AS (
                SELECT so.store_id,date_trunc('month',sr.returned_at)::date AS month,
                    SUM(COALESCE(sr.refund_amount, i.revenue*sr.qty/i.qty)) AS refund,
                    SUM(sr.qty*i.reseller_unit_price) FILTER(WHERE sr.credit_reseller AND i.revenue IS NOT NULL) AS credit
                FROM sales_returns sr JOIN sales_order_items i ON i.id=sr.sales_order_item_id JOIN sales_orders so ON so.id=i.sales_order_id
                WHERE sr.returned_at<=p_as_of AND so.status<>'cancelled' GROUP BY 1,2
            ), ad AS (
                SELECT store_id,date_trunc('month',spend_date)::date AS month,SUM(amount) AS actual FROM store_ad_spends WHERE spend_date<=p_as_of GROUP BY 1,2
            ), periods AS (SELECT store_id,month FROM sold UNION SELECT store_id,month FROM returns UNION SELECT store_id,month FROM ad), totals AS (
                SELECT p.store_id,p.month,COALESCE(s.revenue,0)-COALESCE(r.refund,0) AS revenue,
                    COALESCE(s.cost,0)-COALESCE(r.credit,0) AS cost,COALESCE(s.fee,0) AS fee,COALESCE(s.estimated,false) AS estimated,
                    COALESCE(ad.actual,GREATEST(COALESCE(s.ad_estimate,0),0)) AS ads,ad.actual IS NULL AS ads_estimated
                FROM periods p LEFT JOIN sold s USING(store_id,month) LEFT JOIN returns r USING(store_id,month) LEFT JOIN ad USING(store_id,month)
            ) SELECT store_id||'-'||to_char(month,'YYYY-MM'),store_id,month,revenue,cost,revenue-cost,fee,estimated,ads,ads_estimated,revenue-cost-fee-ads FROM totals;
            $$;
            CREATE OR REPLACE VIEW v_store_monthly_pl AS SELECT * FROM fn_store_pl('infinity'::date);
            CREATE OR REPLACE VIEW v_konveksi_monthly_pl AS
            WITH s AS (SELECT date_trunc('month',order_date)::date AS month,SUM(bill_amount) AS sales,SUM(hpp_amount) AS hpp FROM v_sales_lines WHERE status<>'cancelled' GROUP BY 1),
            rt AS (SELECT date_trunc('month',sr.returned_at)::date AS month,
                COALESCE(SUM(sr.qty*i.reseller_unit_price) FILTER(WHERE sr.credit_reseller),0) AS credit,
                COALESCE(SUM(sr.qty*i.hpp_unit) FILTER(WHERE sr.restock),0) AS hpp
                FROM sales_returns sr JOIN sales_order_items i ON i.id=sr.sales_order_item_id GROUP BY 1),
            e AS (SELECT date_trunc('month',expense_date)::date AS month,SUM(amount) AS expenses FROM expenses GROUP BY 1),
            m AS (SELECT month FROM s UNION SELECT month FROM rt UNION SELECT month FROM e)
            SELECT m.month,COALESCE(s.sales,0)-COALESCE(rt.credit,0) AS net_sales,
                COALESCE(s.hpp,0)-COALESCE(rt.hpp,0) AS hpp,
                COALESCE(s.sales,0)-COALESCE(rt.credit,0)-COALESCE(s.hpp,0)+COALESCE(rt.hpp,0) AS gross_profit,
                COALESCE(e.expenses,0) AS expenses,
                COALESCE(s.sales,0)-COALESCE(rt.credit,0)-COALESCE(s.hpp,0)+COALESCE(rt.hpp,0)-COALESCE(e.expenses,0) AS net_profit
            FROM m LEFT JOIN s USING(month) LEFT JOIN rt USING(month) LEFT JOIN e USING(month);
            SQL);
    }

    public function down(): void
    {
        $original = require __DIR__.'/2026_10_16_160044_create_report_views.php';
        $original->up();
        DB::statement('DROP FUNCTION IF EXISTS fn_store_pl(date)');
    }
};
