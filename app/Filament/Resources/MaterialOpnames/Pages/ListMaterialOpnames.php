<?php

namespace App\Filament\Resources\MaterialOpnames\Pages;

use App\Filament\Resources\MaterialOpnames\MaterialOpnameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialOpnames extends ListRecords
{
    protected static string $resource = MaterialOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
