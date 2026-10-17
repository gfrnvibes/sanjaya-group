<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MASTER CHANNEL: SHOPEE, TIKTOK, LAZADA, BLIBLI, WEBSITE, WA, OFFLINE, LAINNYA.
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->string('kind', 15);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE channels ADD CONSTRAINT channels_kind_check CHECK (kind IN ('marketplace','website','chat','offline','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
