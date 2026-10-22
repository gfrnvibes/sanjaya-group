<?php

namespace App\Filament\Resources\Colors\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Colors\ColorResource;
use App\Imports\ColorImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageColors extends ManageRecords
{
    protected static string $resource = ColorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(ColorImport::class),
            CreateAction::make(),
        ];
    }
}
