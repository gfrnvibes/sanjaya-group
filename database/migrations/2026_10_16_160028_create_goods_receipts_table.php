<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BARANG MASUK manual (borongan, beli jadi, retur, ...) -> stock_movements(goods_in).
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 40)->unique();
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('received_at')->index();
            $table->string('source', 15);
            $table->foreignId('supplier_id')->nullable()->index()->constrained('suppliers');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_source_check CHECK (source IN ('produksi','borongan','beli_jadi','retur','lainnya'))");

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->integer('qty');
            $table->decimal('unit_cost', 15, 2)->nullable();

            $table->unique(['receipt_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_qty_check CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
