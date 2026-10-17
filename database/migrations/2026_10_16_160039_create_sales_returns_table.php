<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retur sebagian / penuh.
     * restock = true         -> stock_movements(return_in) ke warehouse_id;
     * credit_reseller = true -> mengurangi tagihan reseller.
     */
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_item_id')->index()->constrained('sales_order_items');
            $table->date('returned_at')->index();
            $table->integer('qty');
            $table->boolean('restock')->default(true);
            $table->foreignId('warehouse_id')->nullable()->index()->constrained('warehouses');
            $table->boolean('credit_reseller')->default(true);
            $table->foreignId('reseller_invoice_id')->nullable()->index()->constrained('reseller_invoices');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_warehouse_check CHECK (NOT restock OR warehouse_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
