<?php

namespace App\Filament\Resources\LaborTypes\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\LaborTypes\LaborTypeResource;
use App\Imports\LaborTypeImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLaborTypes extends ManageRecords
{
    protected static string $resource = LaborTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(LaborTypeImport::class),
            CreateAction::make(),
        ];
    }
}
