<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PENYESUAIAN (rusak, sampel, custom, koreksi) -> stock_movements(adjustment).
     */
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('adjusted_at')->index();
            $table->string('reason', 15);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_reason_check CHECK (reason IN ('rusak','sampel','custom','koreksi','lainnya'))");

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->integer('qty'); // + tambah, - kurang

            $table->unique(['adjustment_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT stock_adjustment_items_qty_check CHECK (qty <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
