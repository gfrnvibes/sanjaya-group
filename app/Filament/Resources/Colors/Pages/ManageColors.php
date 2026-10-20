<?php

namespace App\Filament\Resources\Colors\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Resources\Colors\ColorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageColors extends ManageRecords
{
    protected static string $resource = ColorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...CatalogActions::make(),
            CreateAction::make(),
        ];
    }
}
