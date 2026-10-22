<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackup extends Command
{
    protected $signature = 'database:backup {--verify : Uji restore ke database sementara yang dibuat khusus}';

    protected $description = 'Backup PostgreSQL ke penyimpanan privat dan verifikasi kemampuan restore';

    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('Backup ini memerlukan PostgreSQL.');

            return self::FAILURE;
        }
        $settings = $connection->getConfig();
        $env = ['PGHOST' => $settings['host'], 'PGPORT' => (string) $settings['port'], 'PGUSER' => $settings['username'], 'PGPASSWORD' => $settings['password'], 'PGSSLMODE' => $settings['sslmode'] ?? 'prefer'];
        $relative = 'backups/database-'.now()->format('Ymd-His').'-'.Str::random(8).'.dump';
        Storage::disk('local')->makeDirectory('backups');
        $path = Storage::disk('local')->path($relative);
        $verifyDatabase = null;
        try {
            (new Process([config('business.pg_dump'), '--format=custom', '--no-owner', '--no-acl', '--file='.$path, '--dbname='.$settings['database']], base_path(), $env, timeout: 300))->mustRun();
            (new Process([config('business.pg_restore'), '--list', $path], base_path(), $env, timeout: 60))->mustRun();
            if ($this->option('verify')) {
                $verifyDatabase = 'sanjaya_restore_'.strtolower(Str::random(12)).'_test';
                $connection->statement('CREATE DATABASE "'.$verifyDatabase.'"');
                (new Process([config('business.pg_restore'), '--exit-on-error', '--no-owner', '--no-acl', '--dbname='.$verifyDatabase, $path], base_path(), $env, timeout: 300))->mustRun();
                $this->info('Uji restore ke database terpisah berhasil.');
            }
            foreach (Storage::disk('local')->files('backups') as $old) {
                if ($old !== $relative && preg_match('/^backups\/database-[0-9]{8}-[0-9]{6}-[a-zA-Z0-9]{8}\.dump$/', $old)
                    && Storage::disk('local')->lastModified($old) < now()->subDays(max(1, (int) config('business.backup_retention_days')))->getTimestamp()) {
                    Storage::disk('local')->delete($old);
                }
            }
            $this->info('Backup privat: '.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup/verifikasi gagal. Periksa log dan ketersediaan pg_dump/pg_restore; tidak ada data sumber yang diubah.');

            return self::FAILURE;
        } finally {
            if ($verifyDatabase !== null) {
                $connection->statement('DROP DATABASE IF EXISTS "'.$verifyDatabase.'"');
            }
        }
    }
}
