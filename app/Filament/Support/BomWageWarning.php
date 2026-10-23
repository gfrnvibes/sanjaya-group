<?php

namespace App\Filament\Support;

use App\Models\Bom;
use App\Services\CostingService;
use Filament\Notifications\Notification;

/**
 * Peringatan resep tanpa ongkos upah jahit / upah potong (dipanggil setelah resep disimpan).
 */
class BomWageWarning
{
    public static function send(Bom $bom): void
    {
        $warnings = app(CostingService::class)->wageWarnings($bom);

        if ($warnings === []) {
            return;
        }

        Notification::make()
            ->title('Resep tersimpan, tapi ongkos upah belum lengkap')
            ->body(implode('<br>', array_map('e', $warnings)).'<br>Tambahkan baris ongkos dengan Jenis Ongkos Kerja yang "Dibayar sebagai" Upah Jahit / Upah Potong, atau abaikan bila memang tidak ada.')
            ->warning()
            ->persistent()
            ->send();
    }
}
