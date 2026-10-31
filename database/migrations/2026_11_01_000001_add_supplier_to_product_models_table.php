<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier default model beli jadi: dipakai tagihan supplier mode "berdasarkan barang keluar" (tanpa stok/lot).
     */
    public function up(): void
    {
        Schema::table('product_models', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('kind')->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
