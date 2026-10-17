<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upah jahit yang dibayar (arus kas, bukan HPP lagi).
     */
    public function up(): void
    {
        Schema::create('tailor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tailor_id')->index()->constrained('tailors');
            $table->foreignId('work_order_id')->nullable()->index()->constrained('work_orders');
            $table->date('paid_at')->index();
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE tailor_payments ADD CONSTRAINT tailor_payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_payments');
    }
};
