<?php

namespace App\Filament\Actions;

use App\Filament\Support\ImportResultNotification;
use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;
use App\Support\ApplicationAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import/Export katalog (Bahan, Produk, Resep BOM, Pembelian Bahan).
 *  - Workbook lengkap (semua sheet yang saling berelasi): di Pusat Import / Export.
 *  - Per halaman resource (sheet()): hanya data resource itu sendiri, mis. halaman Varian = sheet Produk saja.
 */
class CatalogActions
{
    /**
     * Import & Export satu jenis sheet katalog untuk halaman resource-nya.
     *
     * @param  string  $type  kunci CatalogImporter::SHEETS (materials, products, boms, purchases)
     * @return list<ActionGroup>
     */
    public static function sheet(string $type): array
    {
        $title = CatalogImporter::SHEETS[$type]['title'];

        return [
            ActionGroup::make([
                self::templateAction($type),
                self::importAction($type),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ResourceIo::export([
                self::exportAction("exportCatalog_xlsx_{$type}", "Data {$title} (untuk diedit & diimpor ulang)", 'xlsx', $type)
                    ->icon(Heroicon::OutlinedDocumentArrowDown),
                self::exportAction("exportCatalog_csv_{$type}", "Data {$title} – CSV", 'csv', $type),
            ]),
        ];
    }

    /**
     * @param  string|null  $sheet  null = template workbook lengkap
     */
    public static function templateAction(?string $sheet = null): Action
    {
        $slug = $sheet === null ? 'katalog' : Str::slug(CatalogImporter::SHEETS[$sheet]['title']);

        return Action::make('downloadCatalogTemplate'.($sheet === null ? '' : "_{$sheet}"))
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola katalog') && ApplicationAccess::allows('kelola stok'))
            ->label($sheet === null ? 'Unduh Template Katalog' : 'Unduh Template')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (CatalogSpreadsheet $spreadsheet): BinaryFileResponse => response()
                ->download($spreadsheet->template($sheet === null ? null : [$sheet]), "template-import-{$slug}.xlsx")
                ->deleteFileAfterSend());
    }

    public static function exportAction(string $name, string $label, string $format, ?string $sheet = null): Action
    {
        return Action::make($name)
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola katalog') && ApplicationAccess::allows('kelola stok'))
            ->label($label)
            ->action(fn (CatalogSpreadsheet $spreadsheet): BinaryFileResponse => response()
                ->download(
                    $spreadsheet->export($format, $sheet),
                    'katalog'.($sheet ? '-'.str_replace('_', '-', $sheet) : '').'-'.now()->format('Ymd-His').'.'.$format,
                )
                ->deleteFileAfterSend());
    }

    /**
     * @param  string|null  $sheet  null = workbook lengkap (semua sheet)
     */
    public static function importAction(?string $sheet = null): Action
    {
        $title = $sheet === null ? null : CatalogImporter::SHEETS[$sheet]['title'];

        return Action::make('importCatalog'.($sheet === null ? '' : "_{$sheet}"))
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola katalog') && ApplicationAccess::allows('kelola stok'))
            ->label($sheet === null ? 'Import Katalog' : 'Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading($sheet === null ? 'Import Katalog Produk, Bahan & HPP' : "Import {$title}")
            ->modalDescription($sheet === null
                ? 'Satu workbook berisi sheet Bahan, Produk, Resep BOM, dan Pembelian Bahan (boleh sebagian, atau 1 file CSV per sheet). Data yang sudah ada diperbarui dari sel yang terisi. HPP & harga reseller dihitung otomatis. Baris valid disimpan, baris error dilaporkan.'
                : "Gunakan template atau file Export Data {$title} (header merah = diisi, hijau = info sistem). Hanya sheet {$title} yang diproses; sheet lain di file diabaikan. Data yang sudah ada diperbarui dari sel yang terisi. Untuk mengubah data yang saling berelasi sekaligus, pakai workbook katalog di Pusat Import / Export.")
            ->modalSubmitActionLabel('Import')
            ->schema([ResourceIo::fileUpload('imports/catalog')])
            ->action(fn (array $data, CatalogImporter $importer) => ImportResultNotification::send(
                $title === null ? 'katalog' : Str::lower($title),
                ResourceIo::runWithUpload($data, fn (string $path, string $name): array => $importer->import($path, $name, $sheet === null ? null : [$sheet])),
            ));
    }
}
