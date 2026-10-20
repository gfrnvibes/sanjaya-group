<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * JENIS GUDANG: bahan (stok bahan baku), barang_jadi (stok produk), campuran (keduanya).
     * SPK: warehouse_id = gudang bahan (bahan keluar saat potong), goods_warehouse_id = gudang barang jadi (hasil masuk).
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('kind', 12)->default('campuran')->after('name');
        });

        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_kind_check CHECK (kind IN ('bahan','barang_jadi','campuran'))");

        // Gudang yang sudah ada: tebak dari nama.
        DB::statement("UPDATE warehouses SET kind = 'bahan' WHERE name ILIKE '%bahan%'");
        DB::statement("UPDATE warehouses SET kind = 'barang_jadi' WHERE name ILIKE '%jadi%' AND name NOT ILIKE '%bahan%'");

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('goods_warehouse_id')->nullable()->after('warehouse_id')->index()->constrained('warehouses');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_warehouse_id');
        });

        DB::statement('ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS warehouses_kind_check');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
