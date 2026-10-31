<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beli jadi: pembelian (Barang Masuk ber-supplier) = Tagihan Pembelian & stok masuk; penjualan mengambil stok dulu,
     * kekurangannya dikirim langsung supplier (dropship_qty, tanpa mutasi stok) = Tagihan Barang Keluar.
     */
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->integer('dropship_qty')->default(0)->after('qty');
        });
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_dropship_qty_check CHECK (dropship_qty >= 0 AND dropship_qty <= qty)');

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->string('kind', 15)->default('barang_keluar')->after('id');
            $table->foreignId('goods_receipt_item_id')->nullable()->after('sales_order_item_id')->index()->constrained('goods_receipt_items')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE supplier_bill_lines ALTER COLUMN sales_order_item_id DROP NOT NULL');
        DB::statement("ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_kind_check CHECK (kind IN ('pembelian','barang_keluar'))");
        DB::statement("ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_source_check CHECK ((kind = 'pembelian' AND goods_receipt_item_id IS NOT NULL) OR (kind = 'barang_keluar' AND sales_order_item_id IS NOT NULL))");

        // Mode tagihan (barang keluar / FIFO) diganti aturan gabungan di atas.
        DB::table('settings')->where('key', 'supplier_billing_mode')->delete();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_source_check');
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_kind_check');
        DB::table('supplier_bill_lines')->whereNull('sales_order_item_id')->delete();
        DB::statement('ALTER TABLE supplier_bill_lines ALTER COLUMN sales_order_item_id SET NOT NULL');
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_item_id');
            $table->dropColumn('kind');
        });

        DB::statement('ALTER TABLE sales_order_items DROP CONSTRAINT IF EXISTS sales_order_items_dropship_qty_check');
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('dropship_qty');
        });
    }
};
