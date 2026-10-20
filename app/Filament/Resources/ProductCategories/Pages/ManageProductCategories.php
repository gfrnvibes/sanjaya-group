<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductCategories extends ManageRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...CatalogActions::make(),
            CreateAction::make(),
        ];
    }
}
