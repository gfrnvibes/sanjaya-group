<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Filament\Resources\Boms\BomResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\PricingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBom extends EditRecord
{
    protected static string $resource = BomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Resep berubah: HPP & harga reseller varian model dihitung ulang, berlaku mulai tanggal resep.
     */
    protected function afterSave(): void
    {
        PriceSyncNotification::send(app(PricingService::class)->syncForBom($this->getRecord()));
    }
}
