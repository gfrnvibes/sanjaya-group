<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPK / list potongan.
     * Dipotong -> material_movements(production_out) per bom_item dari warehouse_id;
     * Selesai  -> stock_movements(production_in, qty_good) ke warehouse_id.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('tailor_id')->nullable()->index()->constrained('tailors');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->date('cut_date')->index();
            $table->date('finished_date')->nullable();
            $table->string('status', 10)->default('cutting');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check CHECK (status IN ('cutting','sewing','done','cancelled'))");
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_finished_date_check CHECK (finished_date IS NULL OR finished_date >= cut_date)');

        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->integer('qty_cut');
            $table->integer('qty_good')->default(0);
            $table->integer('qty_reject')->default(0);
            $table->decimal('material_cost_snapshot', 15, 2)->nullable(); // biaya bahan/pcs saat dipotong
            $table->decimal('labor_cost_snapshot', 15, 2)->nullable();    // ongkos/pcs saat dipotong

            $table->unique(['work_order_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_qty_cut_check CHECK (qty_cut > 0)');
        DB::statement('ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_qty_good_check CHECK (qty_good >= 0)');
        DB::statement('ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_qty_reject_check CHECK (qty_reject >= 0)');
        DB::statement('ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_qty_total_check CHECK (qty_good + qty_reject <= qty_cut)');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
    }
};
