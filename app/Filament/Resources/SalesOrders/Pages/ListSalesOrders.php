<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesImportBatches\SalesImportBatchResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Warehouse;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListSalesOrders extends ListRecords
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('downloadTemplate')
                    ->label('Unduh Template')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(fn (SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download($spreadsheet->template(), 'template-import-pesanan.xlsx')
                        ->deleteFileAfterSend()),
                $this->importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ActionGroup::make(collect(SalesOrderSpreadsheet::FORMATS)
                ->map(fn (string $label, string $format): Action => Action::make('export_'.$format)
                    ->label($label)
                    ->action(fn (SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download(
                            $spreadsheet->export($this->getFilteredSortedTableQuery(), $format),
                            $spreadsheet->fileName('pesanan', $format),
                        )
                        ->deleteFileAfterSend()))
                ->values()
                ->all())
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->tooltip('Export pesanan sesuai filter tabel')
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import Pesanan dari Excel')
            ->modalDescription('Bisa beberapa file sekaligus (1 file = 1 batch impor). Kolom wajib: TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, QTY, PENDAPATAN TOKO. Harga reseller, margin, dan total tagihan dihitung otomatis. Baris valid disimpan, baris error dilaporkan; baris yang sama persis dengan pesanan yang sudah ada (termasuk dari file lain di unggahan yang sama) dilewati.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('files')
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
                $paths = array_values((array) $data['files']);
                $names = (array) ($data['file_names'] ?? []);

                try {
                    $result = $importer->importMany(
                        array_map(fn (string $path): array => ['path' => $disk->path($path), 'name' => $names[$path] ?? basename($path)], $paths),
                        [
                            'warehouse_id' => (int) $data['warehouse_id'],
                            'status' => $data['status'],
                            'source' => $data['source'] instanceof SalesImportSource ? $data['source']->value : $data['source'],
                        ],
                    );
                } finally {
                    $disk->delete($paths);
                }

                $errors = collect($result['errors']);
                $lines = collect([e(($result['files'] > 1 ? "{$result['files']} file, " : '')
                    ."{$result['rows']} baris dibaca: {$result['created']} tersimpan, {$result['skipped']} dilewati (sudah pernah diimpor), {$errors->count()} gagal.")]);

                if ($errors->isNotEmpty()) {
                    $lines->push('', ...$errors->take(10)->map(fn (string $error): string => e($error))->all());

                    if ($errors->count() > 10) {
                        $lines->push('… dan '.($errors->count() - 10).' error lainnya.');
                    }
                }

                $notification = Notification::make()
                    ->title(match (true) {
                        $errors->isEmpty() => "{$result['created']} pesanan berhasil diimpor",
                        $result['created'] > 0 => "{$result['created']} pesanan tersimpan, {$errors->count()} baris gagal",
                        default => 'Import gagal, tidak ada pesanan yang tersimpan',
                    })
                    ->body($lines->implode('<br>'))
                    ->actions([
                        Action::make('viewBatch')
                            ->label(count($result['batches']) > 1 ? 'Lihat riwayat impor' : 'Lihat batch impor')
                            ->url(count($result['batches']) > 1
                                ? SalesImportBatchResource::getUrl('index')
                                : SalesImportBatchResource::getUrl('view', ['record' => $result['batches'][0]])),
                    ]);

                match (true) {
                    $errors->isEmpty() => $notification->success(),
                    $result['created'] > 0 => $notification->warning()->persistent(),
                    default => $notification->danger()->persistent(),
                };

                $notification->send();
            });
    }
}
