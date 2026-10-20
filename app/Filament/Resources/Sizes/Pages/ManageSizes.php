<?php

namespace App\Filament\Resources\Sizes\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Resources\Sizes\SizeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSizes extends ManageRecords
{
    protected static string $resource = SizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...CatalogActions::make(),
            CreateAction::make(),
        ];
    }
}
