<?php

namespace App\Filament\Resources\ResellerInvoices\Pages;

use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use App\Services\InvoiceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateResellerInvoice extends CreateRecord
{
    protected static string $resource = ResellerInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        if (blank($data['invoice_no'] ?? null)) {
            unset($data['invoice_no']);
        }

        return app(InvoiceService::class)->generate($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
