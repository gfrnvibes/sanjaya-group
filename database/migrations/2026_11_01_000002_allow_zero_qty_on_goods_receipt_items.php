<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Barang masuk qty 0: hanya mencatat harga beli (HPP & harga reseller beli jadi) tanpa menambah stok.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE goods_receipt_items DROP CONSTRAINT goods_receipt_items_qty_check');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_qty_check CHECK (qty >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE goods_receipt_items DROP CONSTRAINT goods_receipt_items_qty_check');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_qty_check CHECK (qty > 0)');
    }
};
