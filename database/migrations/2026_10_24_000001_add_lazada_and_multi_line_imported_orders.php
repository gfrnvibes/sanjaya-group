<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * - Sumber impor Lazada.
     * - Export BigSeller / Website: 1 nomor pesanan marketplace bisa berisi beberapa baris produk; tiap baris tetap
     *   1 pesanan di sistem (dikenali lewat import_key), jadi nomor pesanan impor boleh sama. Nomor pesanan manual tetap unik per toko.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_import_batches DROP CONSTRAINT sales_import_batches_source_check');
        DB::statement("ALTER TABLE sales_import_batches ADD CONSTRAINT sales_import_batches_source_check CHECK (source IN ('bigseller','shopee','tiktok','lazada','manual','website'))");

        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_orders_store_id_order_no_unique');
        DB::statement('CREATE UNIQUE INDEX sales_orders_manual_order_no_unique ON sales_orders (store_id, order_no) WHERE import_batch_id IS NULL');
        DB::statement('CREATE INDEX sales_orders_store_id_order_no_index ON sales_orders (store_id, order_no)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sales_orders_store_id_order_no_index');
        DB::statement('DROP INDEX sales_orders_manual_order_no_unique');
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_store_id_order_no_unique UNIQUE (store_id, order_no)');

        DB::statement('ALTER TABLE sales_import_batches DROP CONSTRAINT sales_import_batches_source_check');
        DB::statement("ALTER TABLE sales_import_batches ADD CONSTRAINT sales_import_batches_source_check CHECK (source IN ('bigseller','shopee','tiktok','manual','website'))");
    }
};
