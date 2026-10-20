<?php

namespace App\Filament\Resources\SkuAliases\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SkuAliases\SkuAliasResource;
use App\Imports\SkuAliasImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSkuAliases extends ManageRecords
{
    protected static string $resource = SkuAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Template & export memakai header yang sama: SKU BIG SELLER, SKU VARIAN, NAMA VARIAN, SUMBER.
            ...ResourceIo::make(SkuAliasImport::class),
            CreateAction::make(),
        ];
    }
}
