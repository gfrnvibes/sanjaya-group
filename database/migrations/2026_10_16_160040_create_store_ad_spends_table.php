<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IKLAN aktual (ditanggung toko). Bila kosong, laporan pakai estimasi % channel.
     */
    public function up(): void
    {
        Schema::create('store_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores');
            $table->date('spend_date');
            $table->decimal('amount', 15, 2);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['store_id', 'spend_date']);
        });

        DB::statement('ALTER TABLE store_ad_spends ADD CONSTRAINT store_ad_spends_amount_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('store_ad_spends');
    }
};
