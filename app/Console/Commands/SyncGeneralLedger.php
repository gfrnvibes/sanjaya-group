<?php

namespace App\Console\Commands;

use App\Services\GeneralLedger;
use Illuminate\Console\Command;

/**
 * Susun ulang jurnal otomatis buku besar (pengaman berkala; biasanya disusun saat laporan dibuka).
 */
class SyncGeneralLedger extends Command
{
    protected $signature = 'ledger:sync';

    protected $description = 'Susun ulang jurnal otomatis buku besar dari transaksi (periode yang belum ditutup)';

    public function handle(GeneralLedger $ledger): int
    {
        $result = $ledger->sync();
        $this->info("{$result['entries']} jurnal, {$result['lines']} baris disusun.");

        if ($result['drift'] !== []) {
            $this->warn(count($result['drift']).' akun berubah di periode yang sudah ditutup (lihat Akuntansi > Tutup Buku).');
        }

        return self::SUCCESS;
    }
}
