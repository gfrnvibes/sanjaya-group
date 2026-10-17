<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1 pesanan / resi per toko. Pemilik/reseller/channel diturunkan dari stores.
     * Item -> stock_movements(sale_out) dari warehouse_id.
     */
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses'); // gudang asal barang
            $table->string('order_no', 60); // no pesanan marketplace / nomor WA otomatis
            $table->date('order_date')->index();
            $table->string('status', 10)->default('completed');
            $table->string('buyer_name', 120)->nullable();
            $table->decimal('platform_fee', 15, 2)->nullable(); // potongan admin AKTUAL (NULL = pakai estimasi %)
            $table->decimal('shipping_fee', 15, 2)->nullable();
            $table->foreignId('import_batch_id')->nullable()->index()->constrained('sales_import_batches');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['store_id', 'order_no']);
            $table->index(['store_id', 'order_date']);
        });

        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check CHECK (status IN ('pending','completed','returned','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
