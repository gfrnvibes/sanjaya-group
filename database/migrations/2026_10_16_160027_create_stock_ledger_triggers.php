<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Saldo stok diperbarui trigger dalam transaksi yang sama dengan INSERT ledger.
     * Ledger tidak boleh diubah/dihapus: koreksi = baris baru.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_stock_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              INSERT INTO stock_balances (variant_id, warehouse_id, qty, updated_at)
              VALUES (NEW.variant_id, NEW.warehouse_id, NEW.qty, now())
              ON CONFLICT (variant_id, warehouse_id)
              DO UPDATE SET qty = stock_balances.qty + EXCLUDED.qty, updated_at = now();
              RETURN NEW;
            END $$;

            CREATE TRIGGER stock_movements_balance AFTER INSERT ON stock_movements
              FOR EACH ROW EXECUTE FUNCTION trg_stock_balance();

            CREATE OR REPLACE FUNCTION trg_material_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              INSERT INTO material_balances (material_id, warehouse_id, qty, updated_at)
              VALUES (NEW.material_id, NEW.warehouse_id, NEW.qty, now())
              ON CONFLICT (material_id, warehouse_id)
              DO UPDATE SET qty = material_balances.qty + EXCLUDED.qty, updated_at = now();
              RETURN NEW;
            END $$;

            CREATE TRIGGER material_movements_balance AFTER INSERT ON material_movements
              FOR EACH ROW EXECUTE FUNCTION trg_material_balance();

            CREATE OR REPLACE FUNCTION trg_ledger_readonly() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Ledger % tidak boleh diubah/dihapus, buat baris koreksi', TG_TABLE_NAME;
            END $$;

            CREATE TRIGGER stock_movements_ro BEFORE UPDATE OR DELETE ON stock_movements
              FOR EACH ROW EXECUTE FUNCTION trg_ledger_readonly();
            CREATE TRIGGER material_movements_ro BEFORE UPDATE OR DELETE ON material_movements
              FOR EACH ROW EXECUTE FUNCTION trg_ledger_readonly();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS material_movements_ro ON material_movements;
            DROP TRIGGER IF EXISTS stock_movements_ro ON stock_movements;
            DROP TRIGGER IF EXISTS material_movements_balance ON material_movements;
            DROP TRIGGER IF EXISTS stock_movements_balance ON stock_movements;
            DROP FUNCTION IF EXISTS trg_ledger_readonly();
            DROP FUNCTION IF EXISTS trg_material_balance();
            DROP FUNCTION IF EXISTS trg_stock_balance();
            SQL);
    }
};
