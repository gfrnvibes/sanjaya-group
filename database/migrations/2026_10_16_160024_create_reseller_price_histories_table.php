<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HARGA RESELLER (harga dasar) + BERLAKU MULAI.
     */
    public function up(): void
    {
        Schema::create('reseller_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->date('effective_from');
            $table->decimal('base_price', 15, 2);
            $table->foreignId('variant_cost_id')->nullable()->index()->constrained('variant_costs'); // HPP yang jadi dasar
            $table->decimal('margin_pct', 7, 4)->nullable();
            $table->string('source', 10);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->unique(['variant_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE reseller_price_histories ADD CONSTRAINT reseller_price_histories_base_price_check CHECK (base_price >= 0)');
        DB::statement("ALTER TABLE reseller_price_histories ADD CONSTRAINT reseller_price_histories_source_check CHECK (source IN ('auto','manual','import'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_price_histories');
    }
};
