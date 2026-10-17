<?php

namespace App\Filament\Resources\Tailors\RelationManagers;

use App\Filament\Resources\TailorPayments\TailorPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $relatedResource = TailorPaymentResource::class;

    protected static ?string $title = 'Pembayaran Upah';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
            ]);
    }
}
