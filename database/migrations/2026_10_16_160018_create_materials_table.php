<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STOK BAHAN, mis. "DRILL - HITAM" (jenis + warna).
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_type_id')->constrained('material_types');
            $table->foreignId('color_id')->nullable()->index()->constrained('colors');
            $table->string('code', 60)->unique();
            $table->string('base_uom', 5);
            $table->string('pack_uom', 10)->default('roll');
            $table->decimal('qty_per_pack', 14, 3); // 1 roll drill = 3000 cm
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['material_type_id', 'color_id'])->nullsNotDistinct();
        });

        DB::statement("ALTER TABLE materials ADD CONSTRAINT materials_base_uom_check CHECK (base_uom IN ('cm','m','pcs','gr'))");
        DB::statement('ALTER TABLE materials ADD CONSTRAINT materials_qty_per_pack_check CHECK (qty_per_pack > 0)');
        DB::statement('ALTER TABLE materials ADD CONSTRAINT materials_min_stock_check CHECK (min_stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
