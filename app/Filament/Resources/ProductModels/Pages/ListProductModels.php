<?php

namespace App\Filament\Resources\ProductModels\Pages;

use App\Filament\Resources\ProductModels\ProductModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductModels extends ListRecords
{
    protected static string $resource = ProductModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
