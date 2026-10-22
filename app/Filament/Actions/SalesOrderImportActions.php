<?php

namespace App\Filament\Actions;

use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesImportBatches\SalesImportBatchResource;
use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use App\Models\SalesImportBatch;
use App\Models\Warehouse;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use App\Support\ApplicationAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Template & import pesanan (multi-file), dipakai di menu Pesanan dan menu Import Gagal.
 * Notifikasi hasil menyediakan unduhan file baris gagal (nilai asli + alasan) untuk diperbaiki lalu diimport ulang.
 */
class SalesOrderImportActions
{
    public static function templateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola penjualan'))
            ->label('Unduh Template')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                ->download($spreadsheet->template(), 'template-import-pesanan.xlsx')
                ->deleteFileAfterSend());
    }

    public static function importAction(): Action
    {
        return Action::make('import')
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola penjualan'))
            ->label('Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Pesanan dari Excel')
            ->modalDescription('Bisa beberapa file sekaligus (1 file = 1 batch impor), termasuk file baris gagal yang sudah diperbaiki. Format template (TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, QTY, PENDAPATAN TOKO) atau file export BigSeller (Nama Toko, Nomor SKU Toko, Penjualan Produk Valid, Dana Penjualan Produk Valid + kolom TANGGAL di belakang). Harga reseller, margin, dan total tagihan dihitung otomatis. Baris valid disimpan; baris gagal dicatat beserta alasannya di menu Import Gagal. Dua baris produk yang sama tetap dihitung seluruh qty-nya. Unggahan ulang file dengan isi yang sama tidak menambah transaksi, meskipun nama file diubah. Stok boleh negatif dan tagihan tetap dihitung. Isi NO PESANAN berbeda untuk pesanan berbeda; gunakan nomor yang sama untuk koreksi lintas file. Tanpa nomor/ID, file yang isinya berubah dianggap impor baru. Koreksi dokumen yang sudah ditagih/diretur dikunci.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('files')
                    ->preventFilePathTampering()
                    ->label('File (.xlsx, .csv, .ods) — boleh lebih dari satu')
                    ->multiple()
                    ->maxFiles(20)
                    ->reorderable()
                    ->disk('local')
                    ->directory('imports/sales-orders')
                    ->storeFileNamesIn('file_names')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.oasis.opendocument.spreadsheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                    ])
                    ->maxSize(10240)
                    ->required(),
                DatePicker::make('order_date')
                    ->label('Tanggal Pesanan Cadangan')
                    ->helperText('Untuk file export BigSeller: isi kolom TANGGAL di belakang kolom terakhir; baris yang TANGGAL-nya kosong memakai tanggal ini. SKU dengan penjualan 0 dilewati. File template manual tetap memakai kolom TANGGAL.')
                    ->native(false),
                Select::make('warehouse_id')
                    ->label('Gudang Asal')
                    ->options(fn (): array => Warehouse::goodsOptions())
                    ->default(fn () => Warehouse::defaultForGoods()?->id)
                    ->required(),
                Select::make('status')
                    ->label('Status Pesanan')
                    ->options([
                        SalesOrderStatus::Completed->value => SalesOrderStatus::Completed->getLabel(),
                        SalesOrderStatus::Pending->value => SalesOrderStatus::Pending->getLabel(),
                    ])
                    ->default(SalesOrderStatus::Completed->value)
                    ->required(),
                Select::make('source')
                    ->label('Sumber Data')
                    ->options(SalesImportSource::class)
                    ->default(SalesImportSource::Manual->value)
                    ->required(),
            ])
            ->action(function (array $data, SalesOrderImporter $importer): void {
                $disk = Storage::disk('local');
                $paths = array_map(fn (mixed $path): string => ResourceIo::validatedUploadPath($path, 'imports/sales-orders'), array_values((array) $data['files']));
                $names = (array) ($data['file_names'] ?? []);

                try {
                    $result = $importer->importMany(
                        array_map(fn (string $path): array => ['path' => $disk->path($path), 'name' => $names[$path] ?? basename($path)], $paths),
                        [
                            'warehouse_id' => (int) $data['warehouse_id'],
                            'status' => $data['status'],
                            'order_date' => $data['order_date'] ?? null,
                            'source' => $data['source'] instanceof SalesImportSource ? $data['source']->value : $data['source'],
                        ],
                    );
                } finally {
                    $disk->delete($paths);
                }

                self::notify($result);
            });
    }

    /**
     * @param  array{batches: list<SalesImportBatch>, files: int, rows: int, created: int, skipped: int, fixed: int, errors: list<string>}  $result
     */
    private static function notify(array $result): void
    {
        $errors = collect($result['errors']);
        $lines = collect([e(($result['files'] > 1 ? "{$result['files']} file, " : '')
            ."{$result['rows']} baris dibaca: {$result['created']} tersimpan, {$result['skipped']} dilewati (file/baris atau nomor transaksi sudah tercatat), {$errors->count()} gagal.")]);

        if ($result['fixed'] > 0) {
            $lines->push(e("{$result['fixed']} baris gagal sebelumnya kini sudah diperbaiki."));
        }

        if ($errors->isNotEmpty()) {
            $lines->push('', ...$errors->take(10)->map(fn (string $error): string => e($error))->all());

            if ($errors->count() > 10) {
                $lines->push('… dan '.($errors->count() - 10).' error lainnya.');
            }

            $lines->push('', 'Unduh <b>file baris gagal</b>: berisi alasan & cara memperbaiki tiap baris. Perbaiki lalu import ulang file tersebut.');
        }

        $batchIds = collect($result['batches'])->pluck('id')->implode(',');

        $notification = Notification::make()
            ->title(match (true) {
                $errors->isEmpty() => "{$result['created']} pesanan berhasil diimpor",
                $result['created'] > 0 => "{$result['created']} pesanan tersimpan, {$errors->count()} baris gagal",
                default => 'Import gagal, tidak ada pesanan yang tersimpan',
            })
            ->body($lines->implode('<br>'))
            ->actions(array_values(array_filter([
                $errors->isNotEmpty()
                    ? Action::make('downloadFailures')
                        ->label('Unduh baris gagal')
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->button()
                        ->url(route('filament.administrator.sales-import-failures.download', ['batches' => $batchIds]))
                    : null,
                $errors->isNotEmpty()
                    ? Action::make('viewFailures')
                        ->label('Lihat alasan gagal')
                        ->link()
                        ->url(SalesImportFailureResource::getUrl('index'))
                    : null,
                Action::make('viewBatch')
                    ->label(count($result['batches']) > 1 ? 'Riwayat impor' : 'Lihat batch impor')
                    ->link()
                    ->url(count($result['batches']) > 1
                        ? SalesImportBatchResource::getUrl('index')
                        : SalesImportBatchResource::getUrl('view', ['record' => $result['batches'][0]])),
            ])));

        match (true) {
            $errors->isEmpty() => $notification->success(),
            $result['created'] > 0 => $notification->warning()->persistent(),
            default => $notification->danger()->persistent(),
        };

        $notification->send();
    }
}
