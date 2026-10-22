<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSalesOrder extends ViewRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('complete')
                ->label('Tandai Selesai')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (SalesOrder $record): bool => $record->status === SalesOrderStatus::Pending)
                ->requiresConfirmation()
                ->action(function (SalesOrder $record): void {
                    try {
                        app(SalesOrderService::class)->completePending($record);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->warning()->send();

                        return;
                    }

                    Notification::make()->title('Pesanan selesai')->success()->send();
                }),
            Action::make('cancel')
                ->label('Batalkan Pesanan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (SalesOrder $record): bool => in_array($record->status, [SalesOrderStatus::Pending, SalesOrderStatus::Completed], true))
                ->requiresConfirmation()
                ->modalDescription('Stok dikembalikan ke gudang (batal jual) dan baris dilepas dari tagihan draft.')
                ->action(function (SalesOrder $record, SalesOrderService $service): void {
                    try {
                        $service->cancel($record);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Pesanan dibatalkan')->warning()->send();
                }),
            EditAction::make(),
        ];
    }
}
