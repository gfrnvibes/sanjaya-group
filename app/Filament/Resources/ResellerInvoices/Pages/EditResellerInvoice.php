<?php

namespace App\Filament\Resources\ResellerInvoices\Pages;

use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditResellerInvoice extends EditRecord
{
    protected static string $resource = ResellerInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
