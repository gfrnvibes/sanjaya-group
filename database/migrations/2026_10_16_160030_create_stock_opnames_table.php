<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STOK OPNAME per gudang. Saat posted, selisih -> stock_movements(opname).
     */
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('counted_at');
            $table->string('status', 10)->default('draft');
            $table->timestampTz('posted_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_opnames ADD CONSTRAINT stock_opnames_status_check CHECK (status IN ('draft','posted'))");
        DB::statement("ALTER TABLE stock_opnames ADD CONSTRAINT stock_opnames_posted_at_check CHECK (status = 'draft' OR posted_at IS NOT NULL)");

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->integer('system_qty');
            $table->integer('counted_qty');
            $table->integer('diff')->storedAs('counted_qty - system_qty');

            $table->unique(['opname_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE stock_opname_items ADD CONSTRAINT stock_opname_items_counted_qty_check CHECK (counted_qty >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
