<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\StockOpnameStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockOpname;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewStockOpname extends ViewRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fillFromStock')
                ->label('Isi dari Stok Sistem')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (StockOpname $record): bool => $record->status === StockOpnameStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Tambahkan semua varian yang punya saldo di gudang ini (stok fisik awal = stok sistem). Ubah stok fisik lewat Edit.')
                ->action(function (StockOpname $record, InventoryService $inventory): void {
                    $count = $inventory->fillOpnameFromStock($record);

                    Notification::make()->title("{$count} varian ditambahkan")->success()->send();
                    $record->refresh();
                }),
            Action::make('post')
                ->label('Posting Opname')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (StockOpname $record): bool => $record->status === StockOpnameStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Selisih stok fisik vs sistem akan dicatat ke kartu stok dan opname dikunci.')
                ->action(function (StockOpname $record, InventoryService $inventory): void {
                    try {
                        $inventory->postOpname($record);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Opname diposting')->success()->send();
                    $record->refresh();
                }),
            EditAction::make(),
        ];
    }
}
