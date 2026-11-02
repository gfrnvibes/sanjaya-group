<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laporan mingguan Dashboard Produk: setiap Senin 07:00 WIB (periode 7 hari s.d. Minggu).
Schedule::command('report:weekly-dashboard')
    ->weeklyOn(1, '07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Backup (spatie/laravel-backup) ke disk lokal + Cloudflare R2; riwayat & kesehatannya di menu Pengaturan > Backup.
// Perintah lama database:backup tetap ada untuk uji restore manual (--verify).
$backupEnabled = fn (): bool => (bool) config('business.backup_enabled');
Schedule::command('backup:clean')->dailyAt('01:30')->timezone('Asia/Jakarta')->withoutOverlapping(30)->when($backupEnabled);
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping(60)->when($backupEnabled);
Schedule::command('backup:monitor')->dailyAt('03:00')->timezone('Asia/Jakarta')->when($backupEnabled);

// Tagihan reseller otomatis: pengaman harian (sinkron utama berjalan setiap ada perubahan penjualan / retur).
Schedule::command('invoices:sync')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping();

// Buku besar: jurnal otomatis disusun ulang saat laporan dibuka; ini pengaman berkala.
Schedule::command('ledger:sync')->hourly()->withoutOverlapping();
