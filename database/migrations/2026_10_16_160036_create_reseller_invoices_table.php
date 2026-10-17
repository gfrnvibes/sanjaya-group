<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TAGIHAN konveksi -> reseller per periode.
     */
    public function up(): void
    {
        Schema::create('reseller_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers');
            $table->string('invoice_no', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_at')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 10)->default('draft');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['reseller_id', 'period_start']);
        });

        DB::statement("ALTER TABLE reseller_invoices ADD CONSTRAINT reseller_invoices_status_check CHECK (status IN ('draft','issued','void'))");
        DB::statement('ALTER TABLE reseller_invoices ADD CONSTRAINT reseller_invoices_period_check CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_invoices');
    }
};
