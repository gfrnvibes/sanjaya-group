<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis ongkos kerja: POTONG, JAHIT, BORDIR, OBRAS.
     */
    public function up(): void
    {
        Schema::create('labor_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_types');
    }
};
