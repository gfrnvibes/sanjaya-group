<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PEMBAYARAN reseller -> konveksi. cost_offset = POTONGAN BIAYA (tanpa rekening).
     */
    public function up(): void
    {
        Schema::create('reseller_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers');
            $table->timestampTz('paid_at');
            $table->decimal('amount', 15, 2);
            $table->string('method', 12);
            $table->foreignId('cash_account_id')->nullable()->index()->constrained('cash_accounts');
            $table->string('proof_path')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['reseller_id', 'paid_at']);
        });

        DB::statement('ALTER TABLE reseller_payments ADD CONSTRAINT reseller_payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE reseller_payments ADD CONSTRAINT reseller_payments_method_check CHECK (method IN ('transfer','cash','cost_offset'))");
        DB::statement("ALTER TABLE reseller_payments ADD CONSTRAINT reseller_payments_cash_account_check CHECK (method = 'cost_offset' OR cash_account_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payments');
    }
};
