<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LEDGER barang jadi (append-only) + cache saldo per varian per gudang.
     * Saldo diisi trigger (lihat migration create_stock_ledger_triggers).
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('moved_at');
            $table->integer('qty'); // + masuk, - keluar
            $table->string('movement_type', 20);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->nullableMorphs('source'); // morph map: work_order_item, sales_order_item, ...
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['variant_id', 'moved_at']);
            $table->index(['movement_type', 'moved_at']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_check CHECK (qty <> 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type IN
            ('opening','production_in','goods_in','return_in','sale_out','sale_cancel','adjustment','opname'))");

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->integer('qty')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['variant_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
    }
};
