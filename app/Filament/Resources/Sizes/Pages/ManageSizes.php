<?php

namespace App\Filament\Resources\Sizes\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Sizes\SizeResource;
use App\Imports\SizeImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSizes extends ManageRecords
{
    protected static string $resource = SizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(SizeImport::class),
            CreateAction::make(),
        ];
    }
}
