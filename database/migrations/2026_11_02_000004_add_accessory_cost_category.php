<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ongkos AKSESORIS di resep = taksiran per pcs: SPK selesai menilai barang jadi dengan taksiran itu (lawannya
     * Selisih Produksi). Belanja aksesoris yang sebenarnya dicatat di Biaya Operasional kategori Aksesoris ->
     * akun 5-1310 (HPP), sehingga laba memuat biaya aksesoris yang benar-benar dibayar.
     * Laporan Laba Rugi Konveksi lama (HPP penjualan memakai taksiran resep) tidak mengurangkan belanja ini lagi.
     */
    public function up(): void
    {
        $now = now();

        $accountId = DB::table('accounts')->where('ledger', ChartOfAccounts::KONVEKSI)->where('role', 'recipe_cost')->value('id')
            ?? DB::table('accounts')->insertGetId([
                'ledger' => ChartOfAccounts::KONVEKSI, 'code' => '5-1310', 'name' => 'Biaya Aksesoris (sudah di resep)', 'type' => 'cogs',
                'normal_balance' => 'debit', 'role' => 'recipe_cost', 'cash_flow' => 'operating', 'created_at' => $now, 'updated_at' => $now,
            ]);

        if (DB::table('expense_categories')->where('code', 'AKSESORIS')->doesntExist()) {
            DB::table('expense_categories')->insert(['code' => 'AKSESORIS', 'name' => 'Aksesoris', 'account_id' => $accountId, 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->view(excludeRecipeCost: true);
    }

    public function down(): void
    {
        $this->view(excludeRecipeCost: false);
    }

    private function view(bool $excludeRecipeCost): void
    {
        $expenses = $excludeRecipeCost
            ? "SELECT date_trunc('month',x.expense_date)::date AS month,SUM(x.amount) AS expenses FROM expenses x
               LEFT JOIN expense_categories c ON c.id=x.category_id LEFT JOIN accounts a ON a.id=c.account_id
               WHERE a.role IS DISTINCT FROM 'recipe_cost' GROUP BY 1"
            : "SELECT date_trunc('month',expense_date)::date AS month,SUM(amount) AS expenses FROM expenses GROUP BY 1";

        DB::statement('DROP VIEW IF EXISTS v_konveksi_monthly_pl');
        DB::statement("
            CREATE VIEW v_konveksi_monthly_pl AS
            WITH s AS (SELECT date_trunc('month',order_date)::date AS month,SUM(bill_amount) AS sales,SUM(hpp_amount) AS hpp FROM v_sales_lines WHERE status<>'cancelled' AND NOT is_consignment GROUP BY 1),
            rt AS (SELECT date_trunc('month',sr.returned_at)::date AS month,
                COALESCE(SUM(sr.qty*i.reseller_unit_price) FILTER(WHERE sr.credit_reseller),0) AS credit,
                COALESCE(SUM(sr.qty*i.hpp_unit) FILTER(WHERE sr.restock),0) AS hpp
                FROM sales_returns sr JOIN sales_order_items i ON i.id=sr.sales_order_item_id WHERE NOT i.is_consignment GROUP BY 1),
            e AS ({$expenses}),
            m AS (SELECT month FROM s UNION SELECT month FROM rt UNION SELECT month FROM e)
            SELECT m.month,COALESCE(s.sales,0)-COALESCE(rt.credit,0) AS net_sales,
                COALESCE(s.hpp,0)-COALESCE(rt.hpp,0) AS hpp,
                COALESCE(s.sales,0)-COALESCE(rt.credit,0)-COALESCE(s.hpp,0)+COALESCE(rt.hpp,0) AS gross_profit,
                COALESCE(e.expenses,0) AS expenses,
                COALESCE(s.sales,0)-COALESCE(rt.credit,0)-COALESCE(s.hpp,0)+COALESCE(rt.hpp,0)-COALESCE(e.expenses,0) AS net_profit
            FROM m LEFT JOIN s USING(month) LEFT JOIN rt USING(month) LEFT JOIN e USING(month)
        ");
    }
};
