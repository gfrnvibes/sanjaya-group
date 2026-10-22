<?php

namespace App\Filament\Resources\Owners\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Owners\OwnerResource;
use App\Imports\OwnerImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOwners extends ListRecords
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(OwnerImport::class),
            CreateAction::make(),
        ];
    }
}
