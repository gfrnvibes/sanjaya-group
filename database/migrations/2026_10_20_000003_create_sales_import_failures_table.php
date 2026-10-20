<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris import pesanan yang gagal: nilai asli + alasan, untuk diperbaiki lalu diimport ulang.
     */
    public function up(): void
    {
        Schema::create('sales_import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sales_import_batches')->cascadeOnDelete();
            $table->integer('line')->default(0);
            $table->jsonb('values');
            $table->string('reason', 20);
            $table->text('message');
            $table->string('status', 10)->default('open');
            $table->foreignId('retry_of_id')->nullable()->constrained('sales_import_failures')->nullOnDelete();
            $table->foreignId('resolved_batch_id')->nullable()->constrained('sales_import_batches')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'batch_id']);
        });

        DB::statement("ALTER TABLE sales_import_failures ADD CONSTRAINT sales_import_failures_status_check CHECK (status IN ('open','fixed','retried','ignored'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_import_failures');
    }
};
