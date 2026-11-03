<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index kolom filter / pembersihan yang belum ada (PostgreSQL tidak membuat index otomatis untuk foreign key):
     * log aktivitas per tanggal (activitylog:clean & urutan terbaru), buku besar per supplier / penjahit / toko,
     * model per supplier, tagihan supplier per varian, pembayaran upah per rekening kas.
     */
    private const INDEXES = [
        'activity_log' => ['created_at'],
        'journal_lines' => ['supplier_id', 'tailor_id', 'store_id'],
        'product_models' => ['supplier_id'],
        'supplier_bill_lines' => ['variant_id'],
        'tailor_payments' => ['cash_account_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
