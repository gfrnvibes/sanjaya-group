<?php

namespace App\Filament\Resources\MaterialTypes\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\MaterialTypes\MaterialTypeResource;
use App\Imports\MaterialTypeImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMaterialTypes extends ManageRecords
{
    protected static string $resource = MaterialTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(MaterialTypeImport::class),
            CreateAction::make(),
        ];
    }
}
