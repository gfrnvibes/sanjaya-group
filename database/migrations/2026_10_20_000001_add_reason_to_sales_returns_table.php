<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ALASAN RETUR terstruktur (untuk analisa retur per alasan). NULL = data lama / belum diisi.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('reason', 20)->nullable()->after('qty')->index();
        });

        DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_reason_check CHECK (reason IN ('cacat_jahit','cacat_bahan','salah_ukuran','salah_kirim','tidak_sesuai','rusak_kirim','berubah_pikiran','lainnya'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_returns DROP CONSTRAINT IF EXISTS sales_returns_reason_check');

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
