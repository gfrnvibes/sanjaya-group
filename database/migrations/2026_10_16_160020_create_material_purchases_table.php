<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PEMBELIAN BAHAN (header + item). Item -> material_movements(purchase_in).
     */
    public function up(): void
    {
        Schema::create('material_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->index()->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses'); // gudang tujuan bahan masuk
            $table->date('purchase_date')->index();
            $table->string('ref_no', 60)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('material_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->index()->constrained('material_purchases')->cascadeOnDelete();
            $table->foreignId('material_id')->index()->constrained('materials');
            $table->decimal('qty_pack', 14, 3);
            $table->decimal('qty_base', 14, 3); // isi aktual (cm/pcs)
            $table->decimal('total_price', 15, 2);
            $table->decimal('unit_cost', 15, 4)->storedAs('total_price / qty_base');
        });

        DB::statement('ALTER TABLE material_purchase_items ADD CONSTRAINT material_purchase_items_qty_pack_check CHECK (qty_pack > 0)');
        DB::statement('ALTER TABLE material_purchase_items ADD CONSTRAINT material_purchase_items_qty_base_check CHECK (qty_base > 0)');
        DB::statement('ALTER TABLE material_purchase_items ADD CONSTRAINT material_purchase_items_total_price_check CHECK (total_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_purchase_items');
        Schema::dropIfExists('material_purchases');
    }
};
