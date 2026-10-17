<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Resources\Stores\StoreResource;
use App\Services\StoreImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('downloadTemplate')
                    ->label('Unduh Template')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(fn (StoreImporter $importer): BinaryFileResponse => response()
                        ->download($importer->template(), 'template-import-toko.xlsx')
                        ->deleteFileAfterSend()),
                $this->importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
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
            ->modalHeading('Import Pemilik, Reseller & Toko')
            ->modalDescription('1 baris = 1 toko. Pemilik, reseller, channel, dan tier yang belum ada dibuat otomatis; yang sudah ada dipakai tanpa diubah. Baris valid disimpan, baris error dilaporkan.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File (.xlsx, .csv, .ods)')
                    ->disk('local')
                    ->directory('imports/stores')
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
            ])
            ->action(function (array $data, StoreImporter $importer): void {
                $disk = Storage::disk('local');

                try {
                    $result = $importer->import($disk->path($data['file']), $data['file_name'] ?? basename($data['file']));
                } finally {
                    $disk->delete($data['file']);
                }

                $created = $result['created'];
                $summary = collect([
                    'toko' => $created['stores'],
                    'pemilik baru' => $created['owners'],
                    'reseller baru' => $created['resellers'],
                    'channel baru' => $created['channels'],
                    'riwayat tier' => $created['tiers'],
                ])->filter()->map(fn (int $count, string $label): string => "{$count} {$label}")->implode(', ');

                if ($result['errors'] === []) {
                    Notification::make()
                        ->title('Import selesai')
                        ->body("Tersimpan: {$summary}.")
                        ->success()
                        ->send();

                    return;
                }

                $errors = collect($result['errors']);
                $lines = $errors->take(10)->map(fn (string $error): string => e($error));

                if ($errors->count() > 10) {
                    $lines->push('… dan '.($errors->count() - 10).' error lainnya.');
                }

                Notification::make()
                    ->title($created['stores'] > 0
                        ? "{$created['stores']} toko tersimpan, {$errors->count()} baris gagal"
                        : 'Import gagal, tidak ada toko yang tersimpan')
                    ->body(($summary !== '' ? e("Tersimpan: {$summary}.").'<br><br>' : '').$lines->implode('<br>'))
                    ->color($created['stores'] > 0 ? 'warning' : 'danger')
                    ->icon($created['stores'] > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedXCircle)
                    ->persistent()
                    ->send();
            });
    }
}
