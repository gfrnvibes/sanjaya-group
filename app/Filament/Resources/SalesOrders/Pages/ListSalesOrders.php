<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesImportBatches\SalesImportBatchResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Setting;
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
            ->modalDescription('Kolom wajib: TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, QTY, PENDAPATAN TOKO. Harga reseller, margin, dan total tagihan dihitung otomatis. Bila ada baris yang error, tidak ada data yang disimpan.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File (.xlsx, .csv, .ods)')
                    ->disk('local')
                    ->directory('imports/sales-orders')
                    ->storeFileNamesIn('file_name')
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
                    ->options(fn () => Warehouse::query()->pluck('name', 'id'))
                    ->default(fn () => Setting::valueOf('default_warehouse_id') ?? Warehouse::query()->value('id'))
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

                try {
                    $result = $importer->import($disk->path($data['file']), $data['file_name'] ?? basename($data['file']), [
                        'warehouse_id' => (int) $data['warehouse_id'],
                        'status' => $data['status'],
                        'source' => $data['source'] instanceof SalesImportSource ? $data['source']->value : $data['source'],
                    ]);
                } finally {
                    $disk->delete($data['file']);
                }

                if ($result['errors'] !== []) {
                    $errors = collect($result['errors']);
                    $lines = $errors->take(10)->map(fn (string $error): string => e($error));

                    if ($errors->count() > 10) {
                        $lines->push('… dan '.($errors->count() - 10).' error lainnya.');
                    }

                    Notification::make()
                        ->title('Import gagal, tidak ada data yang disimpan')
                        ->body($lines->implode('<br>'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$result['created']} pesanan berhasil diimpor")
                    ->success()
                    ->actions([
                        Action::make('viewBatch')
                            ->label('Lihat batch impor')
                            ->url(SalesImportBatchResource::getUrl('view', ['record' => $result['batch']])),
                    ])
                    ->send();
            });
    }
}
