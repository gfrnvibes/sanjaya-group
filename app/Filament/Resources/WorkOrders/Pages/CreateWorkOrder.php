<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(WorkOrderService::class)->create($data, array_values($items));
        } catch (BusinessRuleException $exception) {
            Notification::make()->title('SPK tidak bisa disimpan')->body($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
