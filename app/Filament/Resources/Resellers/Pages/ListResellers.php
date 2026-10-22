<?php

namespace App\Filament\Resources\Resellers\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Imports\ResellerImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResellers extends ListRecords
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(ResellerImport::class),
            CreateAction::make(),
        ];
    }
}
