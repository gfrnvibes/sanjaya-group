<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MODEL, mis. BC1 "Baju Chef 01".
     */
    public function up(): void
    {
        Schema::create('product_models', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('category_id')->index()->constrained('product_categories');
            $table->string('kind', 12)->default('produksi');
            $table->decimal('default_margin_pct', 7, 4)->nullable(); // NULL = pakai settings.margin_default
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE product_models ADD CONSTRAINT product_models_kind_check CHECK (kind IN ('produksi','beli_jadi','jasa'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_models');
    }
};
