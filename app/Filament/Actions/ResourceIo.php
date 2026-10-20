<?php

namespace App\Filament\Actions;

use App\Filament\Support\ImportResultNotification;
use App\Imports\RowImporter;
use App\Support\TableExporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tombol Import & Export standar untuk halaman daftar resource:
 *  - Export: kolom tabel yang tampil, sesuai filter/pencarian/urutan (xlsx, csv, ods);
 *  - Import: template + impor lewat RowImporter.
 */
class ResourceIo
{
    /**
     * @param  class-string<RowImporter>|null  $importer
     * @return list<ActionGroup>
     */
    public static function make(?string $importer = null): array
    {
        return array_values(array_filter([
            $importer ? self::import($importer) : null,
            self::export(),
        ]));
    }

    /**
     * @param  list<Action>  $extra  aksi export tambahan (mis. workbook lengkap)
     */
    public static function export(array $extra = []): ActionGroup
    {
        return ActionGroup::make([
            ...collect(TableExporter::FORMATS)
                ->map(fn (string $label, string $format): Action => Action::make("ioExport_{$format}")
                    ->label("Tabel ini – {$label}")
                    ->action(fn (HasTable $livewire, TableExporter $exporter): BinaryFileResponse => response()
                        ->download(
                            $exporter->export($livewire, $format, self::title($livewire)),
                            Str::slug(self::title($livewire)).'-'.now()->format('Ymd-His').'.'.$format,
                        )
                        ->deleteFileAfterSend()))
                ->values()
                ->all(),
            ...$extra,
        ])
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->tooltip('Export data sesuai filter & pencarian tabel')
            ->button()
            ->color('gray');
    }

    /**
     * @param  class-string<RowImporter>  $importer
     */
    public static function import(string $importer): ActionGroup
    {
        $definition = app($importer);

        return ActionGroup::make([
            Action::make('ioTemplate')
                ->label('Unduh Template')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(fn (): BinaryFileResponse => response()
                    ->download(app($importer)->template(), 'template-import-'.Str::slug($definition->title()).'.xlsx')
                    ->deleteFileAfterSend()),
            Action::make('ioImport')
                ->label('Import Excel')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading("Import {$definition->title()}")
                ->modalDescription('Gunakan template (header merah). Baris valid disimpan, baris error dilaporkan; data yang sama persis dengan yang sudah ada dilewati.')
                ->modalSubmitActionLabel('Import')
                ->schema([self::fileUpload('imports/'.Str::slug($definition->title()))])
                ->action(function (array $data) use ($importer, $definition): void {
                    $result = self::runWithUpload($data, fn (string $path, string $name): array => app($importer)->import($path, $name));

                    ImportResultNotification::send($definition->title(), $result);
                }),
        ])
            ->label('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->button()
            ->color('gray');
    }

    /**
     * Isian unggah file spreadsheet (xlsx, ods, csv) ke disk local.
     */
    public static function fileUpload(string $directory, int $maxKb = 20480): FileUpload
    {
        return FileUpload::make('file')
            ->label('File (.xlsx, .ods, .csv)')
            ->disk('local')
            ->directory($directory)
            ->storeFileNamesIn('file_name')
            ->acceptedFileTypes([
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/vnd.ms-excel',
                'text/csv',
                'text/plain',
            ])
            ->maxSize($maxKb)
            ->required();
    }

    /**
     * Jalankan impor untuk file yang diunggah lalu hapus file sementaranya.
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string, string): TResult  $import
     * @return TResult
     */
    public static function runWithUpload(array $data, callable $import): mixed
    {
        $disk = Storage::disk('local');

        try {
            return $import($disk->path($data['file']), $data['file_name'] ?? basename($data['file']));
        } finally {
            $disk->delete($data['file']);
        }
    }

    private static function title(HasTable $livewire): string
    {
        $title = method_exists($livewire, 'getTitle') ? $livewire->getTitle() : 'data';

        return trim(strip_tags($title instanceof Htmlable ? $title->toHtml() : (string) $title)) ?: 'data';
    }
}
