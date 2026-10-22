<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('prefix')->primary();
            $table->bigInteger('last_number');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->unique();
        });
        Schema::table('sales_returns', fn (Blueprint $table) => $table->decimal('refund_amount', 15, 2)->nullable());
        DB::statement('ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_refund_check CHECK (refund_amount >= 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_out() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE current_qty numeric;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtextextended('stock:' || NEW.variant_id || ':' || NEW.warehouse_id, 0));
              SELECT COALESCE(SUM(qty),0) INTO current_qty FROM stock_balances WHERE variant_id=NEW.variant_id AND warehouse_id=NEW.warehouse_id;
              IF NEW.qty < 0 AND current_qty + NEW.qty < 0 THEN
                RAISE EXCEPTION 'Stok barang tidak cukup; periksa stok awal atau lakukan rekonsiliasi.' USING ERRCODE='23514';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER stock_movements_guard BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION guard_stock_out();
            CREATE OR REPLACE FUNCTION guard_material_out() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE current_qty numeric;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtextextended('material:' || NEW.material_id || ':' || NEW.warehouse_id, 0));
              SELECT COALESCE(SUM(qty),0) INTO current_qty FROM material_balances WHERE material_id=NEW.material_id AND warehouse_id=NEW.warehouse_id;
              IF NEW.qty < 0 AND current_qty + NEW.qty < 0 THEN
                RAISE EXCEPTION 'Stok bahan tidak cukup; periksa stok awal atau lakukan rekonsiliasi.' USING ERRCODE='23514';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER material_movements_guard BEFORE INSERT ON material_movements FOR EACH ROW EXECUTE FUNCTION guard_material_out();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_guard ON stock_movements; DROP FUNCTION IF EXISTS guard_stock_out(); DROP TRIGGER IF EXISTS material_movements_guard ON material_movements; DROP FUNCTION IF EXISTS guard_material_out();');
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('import_key'));
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropColumn('refund_amount'));
        Schema::dropIfExists('document_sequences');
    }
};
