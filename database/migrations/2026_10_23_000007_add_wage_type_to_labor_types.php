<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis ongkos kerja: "Dibayar sebagai" (upah jahit / upah potong / kosong = lewat Biaya Operasional),
     * sehingga upah SPK tidak lagi bergantung pada kode persis JAHIT / POTONG.
     * Data lama ditebak dari kode lalu nama (jahit / potong).
     */
    public function up(): void
    {
        Schema::table('labor_types', function (Blueprint $table) {
            $table->string('wage_type', 10)->nullable()->after('name');
        });

        DB::statement("ALTER TABLE labor_types ADD CONSTRAINT labor_types_wage_type_check CHECK (wage_type IS NULL OR wage_type IN ('jahit','potong'))");

        DB::statement("UPDATE labor_types SET wage_type = 'jahit' WHERE upper(code) = 'JAHIT' OR (wage_type IS NULL AND name ILIKE '%jahit%')");
        DB::statement("UPDATE labor_types SET wage_type = 'potong' WHERE upper(code) = 'POTONG' OR (wage_type IS NULL AND name ILIKE '%potong%')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE labor_types DROP CONSTRAINT labor_types_wage_type_check');

        Schema::table('labor_types', fn (Blueprint $table) => $table->dropColumn('wage_type'));
    }
};
