<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;

/**
 * Notifikasi hasil hitung ulang HPP & harga reseller otomatis (PricingService::sync*).
 */
class PriceSyncNotification
{
    /**
     * @param  array{synced: int, skipped: list<string>}  $result
     */
    public static function send(array $result): void
    {
        if ($result['synced'] === 0 && $result['skipped'] === []) {
            return;
        }

        $skipped = collect($result['skipped']);

        Notification::make()
            ->title("Harga reseller otomatis: {$result['synced']} varian dihitung ulang")
            ->body($skipped->isEmpty() ? null : $skipped->count().' dilewati:<br>'
                .$skipped->take(8)->map(fn (string $line): string => e($line))->implode('<br>')
                .($skipped->count() > 8 ? '<br>… dan '.($skipped->count() - 8).' lainnya.' : ''))
            ->color($skipped->isEmpty() ? 'success' : 'warning')
            ->persistent($skipped->isNotEmpty())
            ->send();
    }
}
