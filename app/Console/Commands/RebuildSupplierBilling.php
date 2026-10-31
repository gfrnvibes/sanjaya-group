<?php

namespace App\Console\Commands;

use App\Services\SupplierBilling;
use Illuminate\Console\Command;

/**
 * Hitung ulang tagihan supplier dari awal. Hanya pesanan mulai --from yang ditagihkan. Pembayaran supplier tidak berubah.
 * --mode=barang_keluar: tiap pesanan ditagihkan ke supplier Model Produk (tanpa stok); --mode=stok: FIFO lot Barang Masuk.
 */
class RebuildSupplierBilling extends Command
{
    protected $signature = 'supplier-billing:rebuild
        {--from= : Tagihkan pesanan mulai tanggal ini (YYYY-MM-DD); default pengaturan / 2026-08-01}
        {--mode= : barang_keluar atau stok; default mode yang sedang dipakai}';

    protected $description = 'Hitung ulang tagihan supplier barang jadi (berdasarkan barang keluar, atau FIFO lot Barang Masuk Beli Jadi)';

    public function handle(SupplierBilling $billing): int
    {
        $mode = $this->option('mode') ?: null;

        if ($mode !== null && ! isset(SupplierBilling::MODES[$mode])) {
            $this->error('Mode harus '.implode(' atau ', array_keys(SupplierBilling::MODES)).'.');

            return self::FAILURE;
        }

        $result = $billing->rebuild($this->option('from') ?: null, $mode);

        $this->info("Tagihan supplier dihitung ulang mulai {$billing->startDate()} (".SupplierBilling::MODES[$billing->mode()].'): '
            ."{$result['lots']} lot, {$result['lines']} baris tagihan, {$result['unallocated']} pcs tanpa supplier, {$result['no_price']} pcs tanpa harga beli.");

        return self::SUCCESS;
    }
}
