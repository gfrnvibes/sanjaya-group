<?php

namespace App\Filament\Resources\MaterialOpnames\Pages;

use App\Filament\Resources\MaterialOpnames\MaterialOpnameResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterialOpname extends CreateRecord
{
    protected static string $resource = MaterialOpnameResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
