<?php

namespace App\Filament\Resources\ResellerReceivables\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ResellerReceivables\ResellerReceivableResource;
use Filament\Resources\Pages\ManageRecords;

class ManageResellerReceivables extends ManageRecords
{
    protected static string $resource = ResellerReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
        ];
    }
}
