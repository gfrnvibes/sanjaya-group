<?php

namespace App\Filament\Resources\PriceTiers\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\PriceTiers\PriceTierResource;
use App\Imports\PriceTierImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePriceTiers extends ManageRecords
{
    protected static string $resource = PriceTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(PriceTierImport::class),
            CreateAction::make(),
        ];
    }
}
