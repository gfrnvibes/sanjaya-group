<?php

namespace App\Filament\Actions;

use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tombol Import/Export workbook katalog (Produk serta Bahan & HPP).
 * Dipasang di halaman Varian / SKU dan Stok Bahan.
 */
class CatalogActions
{
    /**
     * @return list<ActionGroup>
     */
    public static function make(): array
    {
        return [
            ActionGroup::make([
                Action::make('downloadCatalogTemplate')
                    ->label('Unduh Template Katalog')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(fn (CatalogSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download($spreadsheet->template(), 'template-import-katalog.xlsx')
                        ->deleteFileAfterSend()),
                self::importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ActionGroup::make([
                ...collect(CatalogSpreadsheet::FORMATS)
                    ->map(fn (string $label, string $format): Action => self::exportAction("exportCatalog_{$format}", "{$label} – semua sheet", $format))
                    ->values()
                    ->all(),
                ...collect(CatalogImporter::SHEETS)
                    ->map(fn (array $sheet, string $type): Action => self::exportAction("exportCatalog_csv_{$type}", "CSV – {$sheet['title']}", 'csv', $type))
                    ->values()
                    ->all(),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->tooltip('Export katalog lengkap; hasilnya bisa diedit lalu diimpor ulang')
                ->button()
                ->color('gray'),
        ];
    }

    private static function exportAction(string $name, string $label, string $format, ?string $sheet = null): Action
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

    private static function importAction(): Action
    {
        return Action::make('importCatalog')
            ->label('Import Katalog')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Katalog Produk, Bahan & HPP')
            ->modalDescription('Satu workbook berisi sheet Bahan, Produk, Resep BOM, dan Pembelian Bahan (boleh sebagian, atau 1 file CSV per sheet). Data yang sudah ada diperbarui dari sel yang terisi. Baris valid disimpan, baris error dilaporkan.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File (.xlsx, .ods, .csv)')
                    ->disk('local')
                    ->directory('imports/catalog')
                    ->storeFileNamesIn('file_name')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.oasis.opendocument.spreadsheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                    ])
                    ->maxSize(20480)
                    ->required(),
            ])
            ->action(function (array $data, CatalogImporter $importer): void {
                $disk = Storage::disk('local');

                try {
                    $result = $importer->import($disk->path($data['file']), $data['file_name'] ?? basename($data['file']));
                } finally {
                    $disk->delete($data['file']);
                }

                $summary = collect($result['stats'])
                    ->map(fn (array $counts, string $label): string => $label.': '.collect([
                        'baru' => $counts['created'],
                        'diperbarui' => $counts['updated'],
                        'dilewati (sudah ada)' => $counts['skipped'],
                    ])->filter()->map(fn (int $count, string $kind): string => "{$count} {$kind}")->implode(', '))
                    ->values();

                $body = $summary->isEmpty()
                    ? collect(['Tidak ada data yang berubah.'])
                    : $summary->map(fn (string $line): string => e($line));

                $errors = collect($result['errors']);

                if ($errors->isNotEmpty()) {
                    $body->push('', '<b>'.$errors->count().' error:</b>');
                    $body->push(...$errors->take(15)->map(fn (string $error): string => e($error))->all());

                    if ($errors->count() > 15) {
                        $body->push('… dan '.($errors->count() - 15).' error lainnya.');
                    }
                }

                $notification = Notification::make()
                    ->title(match (true) {
                        $errors->isEmpty() => 'Import katalog selesai',
                        $summary->isNotEmpty() => 'Import katalog selesai sebagian',
                        default => 'Import katalog gagal',
                    })
                    ->body($body->implode('<br>'));

                match (true) {
                    $errors->isEmpty() => $notification->success(),
                    $summary->isNotEmpty() => $notification->warning()->persistent(),
                    default => $notification->danger()->persistent(),
                };

                $notification->send();
            });
    }
}
