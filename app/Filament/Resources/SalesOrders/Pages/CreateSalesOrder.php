<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Services\SalesOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(SalesOrderService::class)->create($data, array_values($items));
        } catch (BusinessRuleException $exception) {
            Notification::make()->title('Pesanan tidak bisa disimpan')->body($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
