<?php

namespace App\Filament\Resources\MaterialTypes\Pages;

use App\Filament\Resources\MaterialTypes\MaterialTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMaterialTypes extends ManageRecords
{
    protected static string $resource = MaterialTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
