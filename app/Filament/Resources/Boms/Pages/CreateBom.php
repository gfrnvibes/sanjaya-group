<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Filament\Resources\Boms\BomResource;
use App\Filament\Support\BomWageWarning;
use App\Filament\Support\PriceSyncNotification;
use App\Services\PricingService;
use Filament\Resources\Pages\CreateRecord;

class CreateBom extends CreateRecord
{
    protected static string $resource = BomResource::class;

    /**
     * Resep baru: HPP & harga reseller varian model dihitung otomatis, berlaku mulai tanggal resep.
     */
    protected function afterCreate(): void
    {
        PriceSyncNotification::send(app(PricingService::class)->syncForBom($this->getRecord()));
        BomWageWarning::send($this->getRecord());
    }
}
