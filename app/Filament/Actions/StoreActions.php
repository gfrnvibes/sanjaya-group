<?php

namespace App\Filament\Actions;

use App\Filament\Support\ImportResultNotification;
use App\Services\StoreImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Workbook Reseller & Toko: export -> edit di Excel -> import ulang.
 * Dipasang di semua resource grup Reseller & Toko dan di Pusat Import / Export.
 */
class StoreActions
{
    /**
     * @return list<ActionGroup>
     */
    public static function make(): array
    {
        return [
            ActionGroup::make([
                self::templateAction(),
                self::importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ResourceIo::export([self::exportAction()]),
        ];
    }

    public static function exportAction(): Action
    {
        return Action::make('exportStores')
            ->label('Workbook Toko (untuk diedit & diimpor ulang)')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (StoreImporter $importer): BinaryFileResponse => response()
                ->download($importer->export(), 'reseller-toko-'.now()->format('Ymd-His').'.xlsx')
                ->deleteFileAfterSend());
    }

    public static function templateAction(): Action
    {
        return Action::make('downloadStoreTemplate')
            ->label('Unduh Template Toko')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (StoreImporter $importer): BinaryFileResponse => response()
                ->download($importer->template(), 'template-import-toko.xlsx')
                ->deleteFileAfterSend());
    }

    public static function importAction(): Action
    {
        return Action::make('importStores')
            ->label('Import Pemilik, Reseller & Toko')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Pemilik, Reseller & Toko')
            ->modalDescription('1 baris = 1 toko. Data yang sudah ada (cocok lewat ID atau nama) diperbarui dari sel yang terisi; data baru dibuat. Baris yang dihapus di Excel tidak menghapus data — gunakan AKTIF = Tidak. Baris valid disimpan, baris error dilaporkan.')
            ->modalSubmitActionLabel('Import')
            ->schema([ResourceIo::fileUpload('imports/stores')])
            ->action(fn (array $data, StoreImporter $importer) => ImportResultNotification::send(
                'reseller & toko',
                ResourceIo::runWithUpload($data, fn (string $path, string $name): array => $importer->import($path, $name)),
            ));
    }
}
