<?php

namespace App\Filament\Resources\MaterialOpnames\Pages;

use App\Enums\StockOpnameStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MaterialOpnames\MaterialOpnameResource;
use App\Models\MaterialOpname;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewMaterialOpname extends ViewRecord
{
    protected static string $resource = MaterialOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fillFromStock')
                ->label('Isi dari Stok Sistem')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (MaterialOpname $record): bool => $record->status === StockOpnameStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Tambahkan semua bahan yang punya saldo di gudang ini (stok fisik awal = stok sistem). Ubah stok fisik lewat Edit.')
                ->action(function (MaterialOpname $record, InventoryService $inventory): void {
                    $count = $inventory->fillMaterialOpname($record);

                    Notification::make()->title("{$count} bahan ditambahkan")->success()->send();
                    $record->refresh();
                }),
            Action::make('post')
                ->label('Posting Opname')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (MaterialOpname $record): bool => $record->status === StockOpnameStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription(fn (MaterialOpname $record): string => 'Selisih stok fisik vs sistem dicatat ke kartu bahan dan dijurnal ke '
                    .($record->is_opening ? 'Saldo Awal (opname awal)' : 'Selisih Persediaan (laba rugi)').'. Opname lalu dikunci.')
                ->action(function (MaterialOpname $record, InventoryService $inventory): void {
                    try {
                        $inventory->postMaterialOpname($record);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Opname bahan diposting')->success()->send();
                    $record->refresh();
                }),
            EditAction::make(),
        ];
    }
}
