<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Enums\GoodsReceiptSource;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PriceSyncNotification;
use App\Services\InventoryService;
use App\Services\PricingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(InventoryService::class)->createGoodsReceipt($data, array_values($items));
        } catch (BusinessRuleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }

    /**
     * Beli jadi: HPP (harga beli + packing) & harga reseller varian beli jadi dihitung ulang mulai tanggal terima.
     */
    protected function afterCreate(): void
    {
        $receipt = $this->getRecord();

        if ($receipt->source === GoodsReceiptSource::BeliJadi) {
            PriceSyncNotification::send(app(PricingService::class)->syncForReadyMade(
                $receipt->items()->pluck('variant_id'),
                $receipt->received_at,
            ));
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
