<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\Channels\ChannelResource;
use App\Imports\ChannelImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(ChannelImport::class),
            CreateAction::make(),
        ];
    }
}
