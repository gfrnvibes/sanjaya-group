<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NAMA TOKO: 1 toko = 1 reseller di 1 channel, mis. "ARR SANJAYA WA".
     */
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->index()->constrained('resellers');
            $table->foreignId('channel_id')->index()->constrained('channels');
            $table->string('name', 120)->unique();
            $table->string('external_ref', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
