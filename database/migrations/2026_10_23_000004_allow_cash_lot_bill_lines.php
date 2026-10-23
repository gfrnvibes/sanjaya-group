<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Baris tagihan dari lot beli jadi TANPA supplier (dibayar tunai saat diterima): stock_lot_id terisi, supplier & nilai kosong.
     * Dicatat agar batal/retur mengembalikan qty ke lot yang benar. Belum teralokasi = tanpa supplier DAN tanpa lot.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT supplier_bill_lines_check');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_check CHECK (qty <> 0 AND (supplier_id IS NOT NULL OR unit_cost IS NULL))');
    }

    public function down(): void
    {
        DB::table('supplier_bill_lines')->whereNull('supplier_id')->whereNotNull('stock_lot_id')->delete();
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT supplier_bill_lines_check');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_check CHECK (qty <> 0 AND (supplier_id IS NOT NULL OR (unit_cost IS NULL AND stock_lot_id IS NULL)))');
    }
};
