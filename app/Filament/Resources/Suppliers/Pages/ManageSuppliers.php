<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Imports\SupplierImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(SupplierImport::class),
            CreateAction::make(),
        ];
    }
}
