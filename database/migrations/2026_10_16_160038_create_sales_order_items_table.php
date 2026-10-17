<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris penjualan + SNAPSHOT harga konveksi saat transaksi (laporan lama tidak berubah).
     */
    public function up(): void
    {
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->index()->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->string('sku_raw', 120)->nullable(); // SKU asli di export (audit pemetaan)
            $table->integer('qty');
            $table->decimal('revenue', 15, 2)->nullable(); // PENDAPATAN TOKO baris ini (NULL = belum ada)

            $table->foreignId('reseller_price_id')->index()->constrained('reseller_price_histories');
            $table->decimal('tier_adjustment_pct', 7, 4)->default(0);
            $table->decimal('reseller_unit_price', 15, 2); // base_price x (1+tier) dibulatkan
            $table->decimal('hpp_unit', 15, 2)->default(0);
            $table->decimal('bill_amount', 15, 2)->storedAs('qty * reseller_unit_price');       // TOTAL TAGIHAN
            $table->decimal('margin', 15, 2)->storedAs('revenue - qty * reseller_unit_price'); // laba kotor toko

            $table->foreignId('reseller_invoice_id')->nullable()->index()->constrained('reseller_invoices');
        });

        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_reseller_unit_price_check CHECK (reseller_unit_price >= 0)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_hpp_unit_check CHECK (hpp_unit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
