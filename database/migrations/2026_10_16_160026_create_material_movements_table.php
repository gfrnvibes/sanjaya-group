<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LEDGER bahan (append-only) + cache saldo per bahan per gudang.
     */
    public function up(): void
    {
        Schema::create('material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('moved_at');
            $table->decimal('qty', 14, 3); // + masuk, - keluar
            $table->string('movement_type', 20);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->nullableMorphs('source'); // morph map: material_purchase_item, work_order_item, ...
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['material_id', 'moved_at']);
        });

        DB::statement('ALTER TABLE material_movements ADD CONSTRAINT material_movements_qty_check CHECK (qty <> 0)');
        DB::statement("ALTER TABLE material_movements ADD CONSTRAINT material_movements_movement_type_check CHECK (movement_type IN
            ('opening','purchase_in','production_out','adjustment','opname'))");

        Schema::create('material_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials');
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->decimal('qty', 14, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['material_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_balances');
        Schema::dropIfExists('material_movements');
    }
};
