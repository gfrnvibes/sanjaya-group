<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MODAL & PRIVE: setoran modal pemilik (kas masuk) dan prive / penarikan pemilik (kas keluar),
     * dipakai Neraca, Arus Kas (pendanaan) dan Laporan Perubahan Modal. Plus tarif pajak laporan laba rugi.
     */
    public function up(): void
    {
        Schema::create('equity_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transacted_at');
            $table->string('type', 10);
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transacted_at');
        });

        DB::statement("ALTER TABLE equity_transactions ADD CONSTRAINT equity_transactions_type_check CHECK (type IN ('setoran','prive'))");
        DB::statement('ALTER TABLE equity_transactions ADD CONSTRAINT equity_transactions_amount_check CHECK (amount > 0)');

        DB::table('settings')->insertOrIgnore(['key' => 'tax_rate', 'value' => json_encode(0), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_transactions');
        DB::table('settings')->where('key', 'tax_rate')->delete();
    }
};
