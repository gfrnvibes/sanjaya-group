<?php

namespace App\Console\Commands;

use App\Services\InvoiceSync;
use Illuminate\Console\Command;

/**
 * Pengaman harian tagihan reseller otomatis: masukkan baris belum ditagih ke draft & hapus draft otomatis kosong.
 */
class SyncResellerInvoices extends Command
{
    protected $signature = 'invoices:sync {--force : Jalankan walau tagihan otomatis dinonaktifkan}';

    protected $description = 'Sinkronkan draft tagihan reseller otomatis dengan penjualan & retur yang belum ditagih';

    public function handle(InvoiceSync $sync): int
    {
        if (! $this->option('force') && ! InvoiceSync::enabled()) {
            $this->warn('Tagihan otomatis nonaktif. Pakai --force untuk tetap menjalankan.');

            return self::SUCCESS;
        }

        $result = $sync->sync(force: true);
        $this->info("{$result['created']} draft baru, {$result['attached']} baris masuk tagihan, {$result['removed']} draft kosong dihapus.");

        return self::SUCCESS;
    }
}
