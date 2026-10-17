<?php

namespace App\Filament\Resources\ResellerPayments\Pages;

use App\Filament\Resources\ResellerPayments\ResellerPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResellerPayments extends ManageRecords
{
    protected static string $resource = ResellerPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
        ];
    }
}
