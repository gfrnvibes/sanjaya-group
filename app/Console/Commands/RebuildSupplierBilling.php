<?php

namespace App\Console\Commands;

use App\Services\SupplierBilling;
use Illuminate\Console\Command;

/**
 * Hitung ulang tagihan supplier beli jadi dari awal: alokasi stok vs dikirim langsung supplier diulang urut tanggal,
 * lalu Tagihan Pembelian & Tagihan Barang Keluar dibuat ulang. Hanya mulai --from yang ditagihkan. Pembayaran tidak berubah.
 */
class RebuildSupplierBilling extends Command
{
    protected $signature = 'supplier-billing:rebuild {--from= : Tagihkan pembelian & pesanan mulai tanggal ini (YYYY-MM-DD); default pengaturan / 2026-08-01}';

    protected $description = 'Hitung ulang tagihan supplier beli jadi (Tagihan Pembelian & Tagihan Barang Keluar)';

    public function handle(SupplierBilling $billing): int
    {
        $result = $billing->rebuild($this->option('from') ?: null);

        $this->info("Tagihan supplier dihitung ulang mulai {$billing->startDate()}: {$result['purchase']} baris pembelian, {$result['goods_out']} baris barang keluar, "
            ."{$result['corrected']} penjualan dikoreksi alokasi stoknya, {$result['no_supplier']} pcs tanpa supplier, {$result['no_price']} pcs tanpa harga beli.");

        return self::SUCCESS;
    }
}
