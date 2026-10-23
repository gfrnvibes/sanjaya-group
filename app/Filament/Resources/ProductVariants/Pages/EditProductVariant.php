<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\PricingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductVariant extends EditRecord
{
    protected static string $resource = ProductVariantResource::class;

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
     * Margin / packing berubah: harga baru berlaku hari ini. Model/warna/ukuran berubah: dihitung dari tanggal resep.
     */
    protected function afterSave(): void
    {
        $variant = $this->getRecord();

        if ($variant->wasChanged(['margin_pct', 'packing_cost'])) {
            PriceSyncNotification::send(app(PricingService::class)->syncVariants([$variant], today()));
        } elseif ($variant->wasChanged(['model_id', 'color_id', 'size_id'])) {
            PriceSyncNotification::send(app(PricingService::class)->syncVariants([$variant]));
        }
    }
}
