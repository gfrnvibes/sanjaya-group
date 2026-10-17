<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RESEP per model (+ukuran opsional), berversi lewat effective_from.
     */
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('product_models');
            $table->foreignId('size_id')->nullable()->index()->constrained('sizes'); // NULL = semua ukuran
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->unique(['model_id', 'size_id', 'effective_from'])->nullsNotDistinct();
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->index()->constrained('boms')->cascadeOnDelete();
            $table->string('line_type', 10);
            $table->foreignId('material_type_id')->nullable()->index()->constrained('material_types');
            $table->string('color_mode', 15)->nullable(); // follow_variant = "IKUT PRODUK"
            $table->foreignId('material_id')->nullable()->index()->constrained('materials'); // bila fixed
            $table->decimal('qty_per_unit', 14, 4)->default(1); // cm / pcs per produk
            $table->foreignId('labor_type_id')->nullable()->index()->constrained('labor_types');
            $table->decimal('labor_rate', 15, 2)->nullable(); // ongkos per pcs
        });

        DB::statement("ALTER TABLE bom_items ADD CONSTRAINT bom_items_line_type_check CHECK (line_type IN ('material','labor'))");
        DB::statement("ALTER TABLE bom_items ADD CONSTRAINT bom_items_color_mode_check CHECK (color_mode IN ('follow_variant','fixed'))");
        DB::statement('ALTER TABLE bom_items ADD CONSTRAINT bom_items_qty_per_unit_check CHECK (qty_per_unit > 0)');
        DB::statement("ALTER TABLE bom_items ADD CONSTRAINT bom_items_line_check CHECK (
            (line_type = 'material' AND material_type_id IS NOT NULL
                AND labor_type_id IS NULL AND labor_rate IS NULL
                AND ((color_mode = 'follow_variant' AND material_id IS NULL)
                  OR (color_mode = 'fixed' AND material_id IS NOT NULL)))
            OR
            (line_type = 'labor' AND labor_type_id IS NOT NULL AND labor_rate IS NOT NULL AND labor_rate >= 0
                AND material_type_id IS NULL AND material_id IS NULL AND color_mode IS NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
    }
};
