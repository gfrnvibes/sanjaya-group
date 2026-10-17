<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis bahan: DRILL, LINEN, JARING, KANCING, ...
     */
    public function up(): void
    {
        Schema::create('material_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('kind', 10);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE material_types ADD CONSTRAINT material_types_kind_check CHECK (kind IN ('kain','aksesoris'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('material_types');
    }
};
