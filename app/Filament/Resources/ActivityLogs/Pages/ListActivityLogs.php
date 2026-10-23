<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Riwayat pembuatan, perubahan, dan penghapusan data serta perubahan hak akses. Klik Rincian untuk melihat nilai sebelum dan sesudah.';
    }

    protected function getHeaderActions(): array
    {
        return ResourceIo::make();
    }
}
