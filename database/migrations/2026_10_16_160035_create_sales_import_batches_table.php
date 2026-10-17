<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impor export BigSeller / marketplace.
     */
    public function up(): void
    {
        Schema::create('sales_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('file_name')->nullable();
            $table->string('status', 10)->default('pending');
            $table->integer('row_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE sales_import_batches ADD CONSTRAINT sales_import_batches_source_check CHECK (source IN ('bigseller','shopee','tiktok','manual','website'))");
        DB::statement("ALTER TABLE sales_import_batches ADD CONSTRAINT sales_import_batches_status_check CHECK (status IN ('pending','done','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_import_batches');
    }
};
