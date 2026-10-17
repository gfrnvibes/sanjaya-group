<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKU VARIAN, mis. BC1HS.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('product_models');
            $table->foreignId('color_id')->nullable()->index()->constrained('colors');
            $table->foreignId('size_id')->nullable()->index()->constrained('sizes');
            $table->string('sku', 40)->unique();
            $table->string('name', 200);
            $table->integer('min_stock')->default(0);
            $table->decimal('margin_pct', 7, 4)->nullable(); // NULL = ikut model / default
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['model_id', 'color_id', 'size_id'])->nullsNotDistinct();
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_min_stock_check CHECK (min_stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
