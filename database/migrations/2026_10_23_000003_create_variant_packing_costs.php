<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PACKING per varian dengan tanggal berlaku (produksi & beli jadi):
     *  - variant_packing_costs: biaya packing mulai tanggal tertentu (amount NULL = kembali ke default);
     *    tanpa riwayat = product_variants.packing_cost, lalu pengaturan default
     *    (packing_cost_default untuk beli jadi, packing_cost_default_production untuk produksi, default 0);
     *  - variant_costs.packing_cost: bagian packing dari HPP (informasi; sudah termasuk di labor_cost,
     *    sehingga total_cost tetap material_cost + labor_cost).
     */
    public function up(): void
    {
        Schema::create('variant_packing_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['variant_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE variant_packing_costs ADD CONSTRAINT variant_packing_costs_amount_check CHECK (amount IS NULL OR amount >= 0)');

        Schema::table('variant_costs', function (Blueprint $table) {
            $table->decimal('packing_cost', 15, 2)->default(0);
        });

        // HPP beli jadi lama: labor_cost = packing.
        DB::statement("UPDATE variant_costs SET packing_cost = labor_cost WHERE source = 'purchase'");

        DB::table('settings')->insertOrIgnore(['key' => 'packing_cost_default_production', 'value' => json_encode(0), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'packing_cost_default_production')->delete();

        Schema::table('variant_costs', function (Blueprint $table) {
            $table->dropColumn('packing_cost');
        });

        Schema::dropIfExists('variant_packing_costs');
    }
};
