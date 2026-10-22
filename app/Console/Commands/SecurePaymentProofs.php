<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SecurePaymentProofs extends Command
{
    protected $signature = 'payments:secure-proofs {--dry-run}';

    protected $description = 'Pindahkan bukti pembayaran lama dari penyimpanan publik ke privat';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $count = 0;
        foreach ($public->allFiles('payment-proofs') as $path) {
            if (! str_starts_with($path, 'payment-proofs/') || str_contains($path, '..') || ! $public->exists($path)) {
                continue;
            }
            if (! $this->option('dry-run')) {
                if ($private->exists($path) && ! hash_equals(hash_file('sha256', $public->path($path)), hash_file('sha256', $private->path($path)))) {
                    $this->error('File privat berbeda sudah ada; file tidak ditimpa.');

                    return self::FAILURE;
                }
                $stream = $public->readStream($path);
                try {
                    if (! $private->put($path, $stream) || $private->size($path) !== $public->size($path)) {
                        $this->error('Salinan bukti gagal diverifikasi; file publik dipertahankan.');

                        return self::FAILURE;
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! hash_equals(hash_file('sha256', $public->path($path)), hash_file('sha256', $private->path($path)))) {
                    $this->error('Checksum bukti berbeda; file publik dipertahankan.');

                    return self::FAILURE;
                }
                if (! $public->delete($path)) {
                    $this->error('File publik belum berhasil dihapus.');

                    return self::FAILURE;
                }
            }
            $count++;
        }
        $this->info("{$count} bukti diperiksa/dipindahkan.");

        return self::SUCCESS;
    }
}
