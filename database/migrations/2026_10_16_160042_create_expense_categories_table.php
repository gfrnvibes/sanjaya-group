<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LISTRIK_AIR, SEWA, GAJI_STAF, GAJI_ADMIN_TOKO, PACKING, ONGKIR, ...
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
