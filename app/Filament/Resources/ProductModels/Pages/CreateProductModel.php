<?php

namespace App\Filament\Resources\ProductModels\Pages;

use App\Filament\Resources\ProductModels\ProductModelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductModel extends CreateRecord
{
    protected static string $resource = ProductModelResource::class;
}
