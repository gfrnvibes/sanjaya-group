<?php

namespace App\Filament\Actions;

use App\Filament\Support\ImportResultNotification;
use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tombol Import/Export workbook katalog (Produk serta Bahan & HPP), dipasang di semua resource kedua grup.
 * Export berisi "tabel ini" (kolom yang tampil) dan workbook katalog lengkap (bisa diedit lalu diimpor ulang).
 */
class CatalogActions
{
    /**
     * @param  bool  $withImport  false untuk resource hanya-baca (Riwayat HPP, Riwayat Harga, Usulan Harga)
     * @return list<ActionGroup>
     */
    public static function make(bool $withImport = true): array
    {
        return array_values(array_filter([
            $withImport ? ActionGroup::make([
                self::templateAction(),
                self::importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray') : null,
            ResourceIo::export([
                ...collect(CatalogSpreadsheet::FORMATS)
                    ->map(fn (string $label, string $format): Action => self::exportAction("exportCatalog_{$format}", "Katalog lengkap – {$label}", $format))
                    ->values()
                    ->all(),
                ...collect(CatalogImporter::SHEETS)
                    ->map(fn (array $sheet, string $type): Action => self::exportAction("exportCatalog_csv_{$type}", "Katalog – CSV {$sheet['title']}", 'csv', $type))
                    ->values()
                    ->all(),
            ]),
        ]));
    }

    public static function templateAction(): Action
    {
        return Action::make('downloadCatalogTemplate')
            ->label('Unduh Template Katalog')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (CatalogSpreadsheet $spreadsheet): BinaryFileResponse => response()
                ->download($spreadsheet->template(), 'template-import-katalog.xlsx')
                ->deleteFileAfterSend());
    }

    public static function exportAction(string $name, string $label, string $format, ?string $sheet = null): Action
    {
        return Action::make($name)
            ->label($label)
            ->action(fn (CatalogSpreadsheet $spreadsheet): BinaryFileResponse => response()
                ->download(
                    $spreadsheet->export($format, $sheet),
                    'katalog'.($sheet ? '-'.str_replace('_', '-', $sheet) : '').'-'.now()->format('Ymd-His').'.'.$format,
                )
                ->deleteFileAfterSend());
    }

    public static function importAction(): Action
    {
        return Action::make('importCatalog')
            ->label('Import Katalog')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Katalog Produk, Bahan & HPP')
            ->modalDescription('Satu workbook berisi sheet Bahan, Produk, Resep BOM, dan Pembelian Bahan (boleh sebagian, atau 1 file CSV per sheet). Data yang sudah ada diperbarui dari sel yang terisi. HPP & harga reseller dihitung otomatis dari resep + margin. Baris valid disimpan, baris error dilaporkan.')
            ->modalSubmitActionLabel('Import')
            ->schema([ResourceIo::fileUpload('imports/catalog')])
            ->action(fn (array $data, CatalogImporter $importer) => ImportResultNotification::send(
                'katalog',
                ResourceIo::runWithUpload($data, fn (string $path, string $name): array => $importer->import($path, $name)),
            ));
    }
}
