<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Imports\GoodsReceiptImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoodsReceipts extends ListRecords
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(GoodsReceiptImport::class),
            CreateAction::make(),
        ];
    }
}
