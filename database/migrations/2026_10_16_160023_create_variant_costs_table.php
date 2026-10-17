<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HPP per varian (riwayat). source: bom (dihitung job), legacy (HPP LAMA), manual.
     */
    public function up(): void
    {
        Schema::create('variant_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->date('effective_from');
            $table->decimal('material_cost', 15, 2)->default(0);
            $table->decimal('labor_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->storedAs('material_cost + labor_cost');
            $table->string('source', 10);
            $table->timestampsTz();

            $table->unique(['variant_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE variant_costs ADD CONSTRAINT variant_costs_source_check CHECK (source IN ('bom','legacy','manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_costs');
    }
};
