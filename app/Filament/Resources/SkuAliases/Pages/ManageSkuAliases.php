<?php

namespace App\Filament\Resources\SkuAliases\Pages;

use App\Filament\Resources\SkuAliases\SkuAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSkuAliases extends ManageRecords
{
    protected static string $resource = SkuAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
