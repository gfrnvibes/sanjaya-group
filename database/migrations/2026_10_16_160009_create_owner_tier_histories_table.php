<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier per PEMILIK + BERLAKU MULAI.
     */
    public function up(): void
    {
        Schema::create('owner_tier_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners');
            $table->foreignId('price_tier_id')->index()->constrained('price_tiers');
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->unique(['owner_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_tier_histories');
    }
};
