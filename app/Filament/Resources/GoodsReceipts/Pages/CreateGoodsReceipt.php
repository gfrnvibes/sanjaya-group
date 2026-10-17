<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Services\InventoryService;
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
