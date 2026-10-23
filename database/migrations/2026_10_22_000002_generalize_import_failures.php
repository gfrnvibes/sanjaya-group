<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Import Perlu Dicek": baris gagal dari SEMUA import (bukan hanya pesanan).
     *  - importer : kategori data (kunci ImportRegistry), mis. sales_orders, goods_receipts, catalog.products;
     *  - upload_id: 1 kali unggah / coba ulang (batch_id hanya untuk import pesanan);
     *  - group_key: baris yang disimpan sebagai 1 dokumen (mis. 1 nota barang masuk) dicoba ulang bersama.
     * Tabel notifications untuk lonceng notifikasi panel (ada baris yang perlu dicek).
     */
    public function up(): void
    {
        Schema::table('sales_import_failures', function (Blueprint $table) {
            $table->string('importer', 40)->default('sales_orders')->after('id');
            $table->uuid('upload_id')->nullable()->after('batch_id');
            $table->string('file_name')->nullable()->after('upload_id');
            $table->string('group_key', 64)->nullable()->after('line');
            $table->foreignId('created_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();

            $table->index(['importer', 'status']);
            $table->index('upload_id');
        });

        DB::statement('ALTER TABLE sales_import_failures ALTER COLUMN batch_id DROP NOT NULL');
        DB::statement('ALTER TABLE sales_import_failures ALTER COLUMN reason TYPE varchar(30)');

        // PENDAPATAN TOKO boleh kosong di template impor: margin (kolom hitung) ikut kosong, bukan error.
        DB::statement('ALTER TABLE sales_order_items ALTER COLUMN margin DROP NOT NULL');

        DB::statement('UPDATE sales_import_failures f SET file_name = b.file_name, created_by = b.imported_by FROM sales_import_batches b WHERE b.id = f.batch_id');

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->jsonb('data'); // Filament membaca data->>'format' (PostgreSQL butuh json)
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        DB::table('sales_import_failures')->whereNull('batch_id')->delete();
        DB::statement('ALTER TABLE sales_import_failures ALTER COLUMN batch_id SET NOT NULL');

        Schema::table('sales_import_failures', function (Blueprint $table) {
            $table->dropIndex(['importer', 'status']);
            $table->dropIndex(['upload_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['importer', 'upload_id', 'file_name', 'group_key']);
        });
    }
};
