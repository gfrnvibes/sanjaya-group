<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Imports\ProductCategoryImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductCategories extends ManageRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(ProductCategoryImport::class),
            CreateAction::make(),
        ];
    }
}
