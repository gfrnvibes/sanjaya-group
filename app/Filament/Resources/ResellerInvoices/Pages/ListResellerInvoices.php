<?php

namespace App\Filament\Resources\ResellerInvoices\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResellerInvoices extends ListRecords
{
    protected static string $resource = ResellerInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            CreateAction::make(),
        ];
    }
}
