<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TAGIHAN SUPPLIER (produk beli jadi):
     *  - stock_lots: 1 lot = 1 baris Barang Masuk sumber Beli Jadi (supplier, harga beli, sisa qty untuk FIFO);
     *  - supplier_bill_lines: tagihan ke supplier per baris pesanan Selesai, diambil FIFO dari lot.
     *    supplier_id NULL = belum teralokasi (stok minus) -> dialokasikan saat ada barang masuk berikutnya.
     *    qty negatif = pengurang (retur yang kembali ke stok);
     *  - supplier_wholesale_prices: harga grosir bordir per supplier per model (min. qty per SKU per pesanan);
     *  - supplier_payments: pembayaran ke supplier (arus kas keluar);
     *  - sales_order_items.is_consignment: pesanan grosir bordir = titipan (harga ke reseller = harga supplier,
     *    tidak dihitung omzet/laba konveksi).
     */
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('supplier_id')->nullable()->index()->constrained('suppliers');
            $table->foreignId('goods_receipt_item_id')->nullable()->unique()->constrained('goods_receipt_items')->cascadeOnDelete();
            $table->timestampTz('received_at');
            $table->decimal('unit_cost', 15, 2);
            $table->integer('qty');
            $table->integer('qty_remaining');
            $table->timestampsTz();

            $table->index(['variant_id', 'received_at']);
        });

        DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_qty_check CHECK (qty > 0 AND qty_remaining >= 0 AND qty_remaining <= qty)');

        Schema::create('supplier_wholesale_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('model_id')->constrained('product_models');
            $table->integer('min_qty');
            $table->decimal('unit_price', 15, 2);
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_id', 'model_id', 'min_qty', 'effective_from']);
        });

        DB::statement('ALTER TABLE supplier_wholesale_prices ADD CONSTRAINT supplier_wholesale_prices_check CHECK (min_qty >= 2 AND unit_price >= 0)');

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->boolean('is_consignment')->default(false);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_item_id')->index()->constrained('sales_order_items')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignId('stock_lot_id')->nullable()->index()->constrained('stock_lots')->nullOnDelete();
            $table->foreignId('sales_return_id')->nullable()->index()->constrained('sales_returns')->cascadeOnDelete();
            $table->date('billed_on');
            $table->integer('qty');
            $table->decimal('unit_cost', 15, 2)->nullable(); // NULL = belum teralokasi
            $table->decimal('amount', 15, 2)->nullable()->storedAs('qty * unit_cost');
            $table->boolean('is_consignment')->default(false);
            $table->timestampsTz();

            $table->index(['supplier_id', 'billed_on']);
        });

        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_check CHECK (qty <> 0 AND (supplier_id IS NOT NULL OR (unit_cost IS NULL AND stock_lot_id IS NULL)))');

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->index()->constrained('suppliers');
            $table->date('paid_at')->index();
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_check CHECK (amount > 0)');

        $this->views(consignment: true);
    }

    public function down(): void
    {
        $this->views(consignment: false);

        // sales_order_items.is_consignment dibiarkan: v_sales_lines (dipakai banyak laporan) bergantung padanya.
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_wholesale_prices');
        Schema::dropIfExists('stock_lots');
    }

    /**
     * v_sales_lines + is_consignment; laba konveksi bulanan tanpa titipan grosir (omzet & HPP sama-sama dikeluarkan).
     */
    private function views(bool $consignment): void
    {
        DB::statement('DROP VIEW IF EXISTS v_konveksi_monthly_pl');

        $flag = ', i.is_consignment';
        DB::statement("
            CREATE OR REPLACE VIEW v_sales_lines AS
            SELECT i.id, o.id AS sales_order_id, o.order_date, o.status, o.store_id, st.reseller_id, r.owner_id, st.channel_id,
                   o.warehouse_id, i.variant_id, i.qty, i.revenue, i.reseller_unit_price, i.bill_amount, i.margin,
                   i.hpp_unit, i.qty * i.hpp_unit AS hpp_amount, i.reseller_invoice_id, o.platform_fee{$flag}
            FROM sales_order_items i
            JOIN sales_orders o ON o.id = i.sales_order_id
            JOIN stores st ON st.id = o.store_id
            JOIN resellers r ON r.id = st.reseller_id
        ");

        $salesFilter = $consignment ? ' AND NOT is_consignment' : '';
        $returnFilter = $consignment ? ' WHERE NOT i.is_consignment' : '';
        DB::statement("
            CREATE VIEW v_konveksi_monthly_pl AS
            WITH s AS (SELECT date_trunc('month',order_date)::date AS month,SUM(bill_amount) AS sales,SUM(hpp_amount) AS hpp FROM v_sales_lines WHERE status<>'cancelled'{$salesFilter} GROUP BY 1),
            rt AS (SELECT date_trunc('month',sr.returned_at)::date AS month,
                COALESCE(SUM(sr.qty*i.reseller_unit_price) FILTER(WHERE sr.credit_reseller),0) AS credit,
                COALESCE(SUM(sr.qty*i.hpp_unit) FILTER(WHERE sr.restock),0) AS hpp
                FROM sales_returns sr JOIN sales_order_items i ON i.id=sr.sales_order_item_id{$returnFilter} GROUP BY 1),
            e AS (SELECT date_trunc('month',expense_date)::date AS month,SUM(amount) AS expenses FROM expenses GROUP BY 1),
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
