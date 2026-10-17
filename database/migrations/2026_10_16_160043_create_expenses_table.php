<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BIAYA OPERASIONAL ditanggung konveksi (termasuk gaji admin toko, packing, ongkir).
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date')->index();
            $table->foreignId('category_id')->index()->constrained('expense_categories');
            $table->decimal('amount', 15, 2);
            $table->foreignId('store_id')->nullable()->index()->constrained('stores');       // opsional: untuk toko mana
            $table->foreignId('reseller_id')->nullable()->index()->constrained('resellers'); // opsional
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts');
            $table->foreignId('reseller_payment_id')->nullable()->index()->constrained('reseller_payments'); // bila dibayar reseller (cost_offset)
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
