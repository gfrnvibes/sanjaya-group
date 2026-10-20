<?php

namespace App\Filament\Resources\ProductModels\Pages;

use App\Filament\Resources\ProductModels\ProductModelResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\PricingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductModel extends EditRecord
{
    protected static string $resource = ProductModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Margin model berubah: harga reseller varian dihitung ulang, berlaku mulai hari ini.
     */
    protected function afterSave(): void
    {
        if ($this->getRecord()->wasChanged('default_margin_pct')) {
            PriceSyncNotification::send(app(PricingService::class)->syncVariants($this->getRecord()->variants()->get(), today()));
        }
    }
}
