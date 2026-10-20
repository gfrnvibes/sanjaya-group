<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Imports\WarehouseImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWarehouses extends ManageRecords
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(WarehouseImport::class),
            CreateAction::make(),
        ];
    }
}
