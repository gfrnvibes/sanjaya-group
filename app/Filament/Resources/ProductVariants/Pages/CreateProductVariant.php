<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\PricingService;
use Filament\Resources\Pages\CreateRecord;

class CreateProductVariant extends CreateRecord
{
    protected static string $resource = ProductVariantResource::class;

    /**
     * Varian baru: HPP & harga reseller dihitung otomatis dari resep model, berlaku mulai tanggal resep.
     */
    protected function afterCreate(): void
    {
        PriceSyncNotification::send(app(PricingService::class)->syncVariants([$this->getRecord()]));
    }
}
