<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tagihan reseller otomatis: siklus per reseller (kosong = pengaturan umum) dan penanda draft buatan sistem.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('invoice_cycle', 10)->nullable()->after('opening_balance_date');
            $table->smallInteger('invoice_cycle_day')->nullable()->after('invoice_cycle');
        });

        Schema::table('reseller_invoices', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('status');
        });

        DB::statement("ALTER TABLE resellers ADD CONSTRAINT resellers_invoice_cycle_check CHECK (invoice_cycle IS NULL OR invoice_cycle IN ('mingguan','bulanan'))");
        DB::statement('ALTER TABLE resellers ADD CONSTRAINT resellers_invoice_cycle_day_check CHECK (invoice_cycle_day IS NULL OR invoice_cycle_day BETWEEN 1 AND 28)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE resellers DROP CONSTRAINT IF EXISTS resellers_invoice_cycle_check');
        DB::statement('ALTER TABLE resellers DROP CONSTRAINT IF EXISTS resellers_invoice_cycle_day_check');

        Schema::table('reseller_invoices', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });

        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['invoice_cycle', 'invoice_cycle_day']);
        });
    }
};
