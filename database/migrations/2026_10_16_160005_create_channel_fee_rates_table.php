<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * % potongan admin & iklan per channel (riwayat, 0.2000 = 20%).
     */
    public function up(): void
    {
        Schema::create('channel_fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels');
            $table->date('effective_from');
            $table->decimal('admin_fee_pct', 7, 4)->default(0);
            $table->decimal('ads_pct', 7, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['channel_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE channel_fee_rates ADD CONSTRAINT channel_fee_rates_admin_fee_pct_check CHECK (admin_fee_pct BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE channel_fee_rates ADD CONSTRAINT channel_fee_rates_ads_pct_check CHECK (ads_pct BETWEEN 0 AND 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_fee_rates');
    }
};
