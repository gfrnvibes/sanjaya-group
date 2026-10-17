<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Filament\Resources\TailorPayments\TailorPaymentResource;
use App\Models\WorkOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class TailorPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'tailorPayments';

    protected static ?string $relatedResource = TailorPaymentResource::class;

    protected static ?string $title = 'Pembayaran Upah';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        /** @var WorkOrder $workOrder */
                        $workOrder = $this->getOwnerRecord();

                        return [...$data, 'tailor_id' => $data['tailor_id'] ?? $workOrder->tailor_id, 'created_by' => auth()->id()];
                    }),
            ]);
    }
}
