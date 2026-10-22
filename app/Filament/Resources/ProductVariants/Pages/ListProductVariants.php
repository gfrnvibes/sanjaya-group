<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductVariants extends ListRecords
{
    protected static string $resource = ProductVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...CatalogActions::sheet('products'),
            CreateAction::make(),
        ];
    }
}
