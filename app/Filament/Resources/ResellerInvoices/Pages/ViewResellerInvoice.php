<?php

namespace App\Filament\Resources\ResellerInvoices\Pages;

use App\Enums\ResellerInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use App\Models\ResellerInvoice;
use App\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewResellerInvoice extends ViewRecord
{
    protected static string $resource = ResellerInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('collect')
                ->label('Tarik Penjualan Baru')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (ResellerInvoice $record): bool => $record->status === ResellerInvoiceStatus::Draft)
                ->action(function (ResellerInvoice $record, InvoiceService $service): void {
                    $count = $service->collect($record);

                    Notification::make()->title("{$count} baris ditambahkan")->success()->send();
                }),
            Action::make('issue')
                ->label('Terbitkan')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->visible(fn (ResellerInvoice $record): bool => $record->status === ResellerInvoiceStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Tagihan terbit dikunci; pesanan di dalamnya tidak bisa dibatalkan.')
                ->action(function (ResellerInvoice $record, InvoiceService $service): void {
                    $service->issue($record);

                    Notification::make()->title('Tagihan diterbitkan')->success()->send();
                }),
            Action::make('void')
                ->label('Batalkan Tagihan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (ResellerInvoice $record): bool => $record->status !== ResellerInvoiceStatus::Void)
                ->requiresConfirmation()
                ->modalDescription('Semua baris dilepas dan bisa ditagihkan ulang di tagihan lain.')
                ->action(function (ResellerInvoice $record, InvoiceService $service): void {
                    try {
                        $service->void($record);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Tagihan dibatalkan')->warning()->send();
                }),
            EditAction::make(),
        ];
    }
}
