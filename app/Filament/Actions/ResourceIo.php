<?php

namespace App\Filament\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\ImportResultNotification;
use App\Imports\RowImporter;
use App\Support\ApplicationAccess;
use App\Support\TableExporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Component;
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
            self::export($importer && app($importer)->hasExport() ? [self::exportDataAction($importer)] : []),
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
                    ->authorize(fn (HasTable $livewire): bool => ! method_exists($livewire, 'getResource') || $livewire::getResource()::canViewAny())
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
        return ActionGroup::make([
            self::templateAction($importer),
            self::importAction($importer),
        ])
            ->label('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->button()
            ->color('gray');
    }

    /**
     * @param  class-string<RowImporter>  $importer
     * @param  string  $prefix  awalan nama aksi bila beberapa importer dipasang di satu halaman
     */
    public static function templateAction(string $importer, string $prefix = 'io'): Action
    {
        $title = app($importer)->title();

        return Action::make("{$prefix}Template")
            ->label('Unduh Template')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (): BinaryFileResponse => response()
                ->download(app($importer)->template(), 'template-import-'.Str::slug($title).'.xlsx')
                ->deleteFileAfterSend());
    }

    /**
     * Export data terkini dalam format template (header merah + kolom sistem hijau), siap diedit lalu diimpor ulang.
     *
     * @param  class-string<RowImporter>  $importer
     */
    public static function exportDataAction(string $importer, string $prefix = 'io'): Action
    {
        $title = app($importer)->title();

        return Action::make("{$prefix}ExportData")
            ->label('Export Data (untuk diedit)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (): BinaryFileResponse => response()
                ->download(app($importer)->export(), Str::slug($title).'-'.now()->format('Ymd-His').'.xlsx')
                ->deleteFileAfterSend());
    }

    /**
     * @param  class-string<RowImporter>  $importer
     * @param  list<Component>  $extraFields  isian tambahan; nilainya diisikan ke properti publik importer bernama sama
     */
    public static function importAction(string $importer, string $prefix = 'io', array $extraFields = []): Action
    {
        $title = app($importer)->title();

        return Action::make("{$prefix}Import")
            ->authorize(fn (): bool => ApplicationAccess::allows(app($importer)->permission()))
            ->label('Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading("Import {$title}")
            ->modalDescription('Gunakan template atau file hasil export (header merah = diisi, hijau = info sistem). Baris valid disimpan, baris error dilaporkan; data yang sama persis dengan yang sudah ada dilewati.')
            ->modalSubmitActionLabel('Import')
            ->schema([self::fileUpload('imports/'.Str::slug($title)), ...$extraFields])
            ->action(function (array $data) use ($importer, $title): void {
                $instance = app($importer);
                $public = get_object_vars($instance); // dari luar kelas: hanya properti publik

                foreach ($data as $key => $value) {
                    if ($key !== 'file' && array_key_exists($key, $public)) {
                        $instance->{$key} = $value;
                    }
                }

                $result = self::runWithUpload($data, fn (string $path, string $name): array => $instance->import($path, $name));

                ImportResultNotification::send($title, $result);
            });
    }

    /**
     * Isian unggah file spreadsheet (xlsx, ods, csv) ke disk local.
     */
    public static function fileUpload(string $directory, int $maxKb = 20480): FileUpload
    {
        return FileUpload::make('file')
            ->preventFilePathTampering()
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
        $path = self::validatedUploadPath($data['file'] ?? null);

        try {
            return $import($disk->path($path), $data['file_name'] ?? basename($path));
        } finally {
            $disk->delete($path);
        }
    }

    public static function validatedUploadPath(mixed $path, string $directory = 'imports'): string
    {
        if (! is_string($path) || ! str_starts_with($path, $directory.'/')
            || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new BusinessRuleException('Lokasi file impor tidak valid. Unggah ulang file melalui formulir.');
        }

        $disk = Storage::disk('local');
        $root = realpath($disk->path($directory));
        $resolved = realpath($disk->path($path));

        if ($root === false || $resolved === false || ! is_file($resolved)
            || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new BusinessRuleException('File impor tidak tersedia. Unggah ulang file melalui formulir.');
        }

        return $path;
    }

    private static function title(HasTable $livewire): string
    {
        $title = method_exists($livewire, 'getTitle') ? $livewire->getTitle() : 'data';

        return trim(strip_tags($title instanceof Htmlable ? $title->toHtml() : (string) $title)) ?: 'data';
    }
}
