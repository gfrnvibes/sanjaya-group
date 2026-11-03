<?php

namespace App\Filament\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Brimham\FilamentBackupMonitor\Pages\BackupRunsPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * BACKUP & MONITORING (spatie/laravel-backup + brimham/filament-backup-monitor):
 * riwayat setiap backup / cek kesehatan / pembersihan, status terakhir per tujuan (lokal & Cloudflare R2),
 * plus tombol backup sekarang, cek kesehatan, dan unduh backup terbaru dari disk lokal.
 */
class Backups extends BackupRunsPage
{
    use HasPageShield;

    protected static ?string $slug = 'backup';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 9;

    private const RUNNING = 'backup:running';

    public static function getNavigationLabel(): string
    {
        return 'Backup';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Backup & Monitoring';
    }

    public function getSubheading(): ?string
    {
        $disks = collect(config('backup.backup.destination.disks'));
        $r2 = $disks->contains('r2') ? 'Cloudflare R2 ✓' : 'Cloudflare R2 belum diatur (isi R2_* di .env)';

        return 'Database + file unggahan dibackup otomatis setiap hari 02:00 WIB, disimpan ke: Lokal ✓ · '.$r2.'. '
            .'Backup lama dibersihkan otomatis (semua disimpan '.config('backup.cleanup.default_strategy.keep_all_backups_for_days').' hari, lalu harian/mingguan/bulanan).'
            .(Cache::has(self::RUNNING) ? ' Backup sedang berjalan…' : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackup')
                ->label('Backup sekarang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Backup database + file unggahan dijalankan di latar belakang ke semua tujuan. Hasilnya muncul di tabel dalam 1–2 menit (muat ulang halaman).')
                ->action(function (): void {
                    if (! Cache::add(self::RUNNING, true, now()->addMinutes(30))) {
                        Notification::make()->title('Backup masih berjalan')->warning()->send();

                        return;
                    }

                    // Setelah respons terkirim: pengguna tidak menunggu proses dump & upload.
                    app()->terminating(function (): void {
                        try {
                            self::ensureWindowsSystemRoot();
                            Artisan::call('backup:run');
                        } finally {
                            Cache::forget(self::RUNNING);
                        }
                    });

                    Notification::make()->title('Backup dimulai')->body('Muat ulang halaman ini 1–2 menit lagi untuk melihat hasilnya.')->success()->send();
                }),
            Action::make('checkHealth')
                ->label('Cek kesehatan')
                ->icon(Heroicon::OutlinedHeart)
                ->color('gray')
                ->action(function (): void {
                    Artisan::call('backup:monitor');
                    Notification::make()->title('Cek kesehatan selesai')->body('Hasil per tujuan tampil di tabel.')->success()->send();
                }),
            Action::make('downloadLatest')
                ->label('Unduh backup terbaru')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): ?StreamedResponse {
                    $latest = self::latestLocalBackup();

                    if ($latest === null) {
                        Notification::make()->title('Belum ada backup lokal')->warning()->send();

                        return null;
                    }

                    return Storage::disk('local')->download($latest);
                }),
        ];
    }

    /**
     * Windows, backup dari tombol (request web): Symfony Process hanya meneruskan variabel lingkungan yang juga ada
     * di $_SERVER (+ semua $_ENV) ke pg_dump. Di request web $_SERVER berisi data request, jadi SystemRoot hilang dan
     * pg_dump 17.6+/18 gagal "could not generate restrict key". Lewat terminal / jadwal tidak terpengaruh.
     */
    private static function ensureWindowsSystemRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && blank($_ENV['SystemRoot'] ?? $_ENV['SYSTEMROOT'] ?? null)) {
            $_ENV['SystemRoot'] = getenv('SystemRoot') ?: (getenv('windir') ?: 'C:\\Windows');
        }
    }

    /**
     * Arsip backup terbaru (zip) di disk lokal.
     */
    public static function latestLocalBackup(): ?string
    {
        $disk = Storage::disk('local');
        $folder = (string) config('backup.backup.name');

        return collect($disk->exists($folder) ? $disk->files($folder) : [])
            ->filter(fn (string $file): bool => Str::endsWith($file, '.zip'))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->first();
    }
}
