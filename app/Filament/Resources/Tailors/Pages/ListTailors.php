<?php

namespace App\Filament\Resources\Tailors\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Tailors\TailorResource;
use App\Imports\TailorImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTailors extends ListRecords
{
    protected static string $resource = TailorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(TailorImport::class),
            CreateAction::make(),
        ];
    }
}
