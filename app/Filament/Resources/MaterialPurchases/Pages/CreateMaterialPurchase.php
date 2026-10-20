<?php

namespace App\Filament\Resources\MaterialPurchases\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\InventoryService;
use App\Services\PricingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMaterialPurchase extends CreateRecord
{
    protected static string $resource = MaterialPurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(InventoryService::class)->createMaterialPurchase($data, array_values($items));
        } catch (BusinessRuleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }

    /**
     * Harga bahan berubah: HPP & harga reseller varian yang memakai bahan ini dihitung ulang mulai tanggal beli.
     */
    protected function afterCreate(): void
    {
        $purchase = $this->getRecord();

        PriceSyncNotification::send(app(PricingService::class)->syncForMaterials(
            $purchase->items()->pluck('material_id'),
            $purchase->purchase_date,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
