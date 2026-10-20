<?php

namespace App\Filament\Actions;

use App\Filament\Support\ImportResultNotification;
use App\Services\StoreImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tombol Import (workbook Toko: pemilik, reseller, toko, channel, tier) + Export tabel,
 * dipasang di semua resource grup Reseller & Toko.
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
                Action::make('downloadStoreTemplate')
                    ->label('Unduh Template Toko')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(fn (StoreImporter $importer): BinaryFileResponse => response()
                        ->download($importer->template(), 'template-import-toko.xlsx')
                        ->deleteFileAfterSend()),
                self::importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ResourceIo::export(),
        ];
    }

    private static function importAction(): Action
    {
        return Action::make('importStores')
            ->label('Import Pemilik, Reseller & Toko')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Pemilik, Reseller & Toko')
            ->modalDescription('1 baris = 1 toko. Pemilik, reseller, channel, dan tier yang belum ada dibuat otomatis; yang sudah ada dipakai tanpa diubah. Baris valid disimpan, baris error dilaporkan.')
            ->modalSubmitActionLabel('Import')
            ->schema([ResourceIo::fileUpload('imports/stores')])
            ->action(function (array $data, StoreImporter $importer): void {
                $result = ResourceIo::runWithUpload($data, fn (string $path, string $name): array => $importer->import($path, $name));
                $labels = [
                    'stores' => 'Toko', 'owners' => 'Pemilik', 'resellers' => 'Reseller',
                    'channels' => 'Channel', 'price_tiers' => 'Tier Harga', 'tiers' => 'Riwayat Tier',
                ];

                ImportResultNotification::send('toko', [
                    'stats' => collect($result['created'])
                        ->filter()
                        ->mapWithKeys(fn (int $count, string $key): array => [$labels[$key] => ['created' => $count, 'updated' => 0, 'skipped' => 0]])
                        ->all(),
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings'],
                ]);
            });
    }
}
