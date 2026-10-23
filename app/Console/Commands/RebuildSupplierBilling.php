<?php

namespace App\Console\Commands;

use App\Services\SupplierBilling;
use Illuminate\Console\Command;

/**
 * Hitung ulang lot & tagihan supplier dari awal (FIFO). Hanya pesanan mulai --from yang ditagihkan;
 * stok sebelumnya tetap ikut dipakai FIFO. Pembayaran supplier tidak berubah.
 */
class RebuildSupplierBilling extends Command
{
    protected $signature = 'supplier-billing:rebuild {--from= : Tagihkan pesanan mulai tanggal ini (YYYY-MM-DD); default pengaturan / 2026-08-01}';

    protected $description = 'Hitung ulang tagihan supplier barang jadi (FIFO lot Barang Masuk Beli Jadi)';

    public function handle(SupplierBilling $billing): int
    {
        $result = $billing->rebuild($this->option('from') ?: null);

        $this->info("Tagihan supplier dihitung ulang mulai {$billing->startDate()}: {$result['lots']} lot, {$result['lines']} baris tagihan, {$result['unallocated']} pcs belum teralokasi.");

        return self::SUCCESS;
    }
}
