<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PEMETAAN SKU BigSeller/marketplace -> varian.
     */
    public function up(): void
    {
        Schema::create('sku_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 120)->unique();
            $table->foreignId('variant_id')->index()->constrained('product_variants');
            $table->string('source', 20)->default('bigseller');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_aliases');
    }
};
