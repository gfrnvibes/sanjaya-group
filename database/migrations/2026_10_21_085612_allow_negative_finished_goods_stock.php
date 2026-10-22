<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tetap serialisasi mutasi per SKU/gudang, tanpa menghalangi penagihan saat stok minus.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_out() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtextextended('stock:' || NEW.variant_id || ':' || NEW.warehouse_id, 0));
              RETURN NEW;
            END $$;
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
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
            SQL);
    }
};
