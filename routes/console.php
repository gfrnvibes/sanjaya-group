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

Schedule::command('database:backup')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping(30)->when(fn (): bool => config('business.backup_enabled') && config('database.default') === 'pgsql');

// Tagihan reseller otomatis: pengaman harian (sinkron utama berjalan setiap ada perubahan penjualan / retur).
Schedule::command('invoices:sync')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping();
