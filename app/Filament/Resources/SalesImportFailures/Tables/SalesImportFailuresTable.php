<?php

namespace App\Filament\Resources\SalesImportFailures\Tables;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Enums\SalesOrderStatus;
use App\Models\ProductVariant;
use App\Models\SalesImportFailure;
use App\Models\Warehouse;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use App\Support\ApplicationAccess;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tabel baris gagal (dipakai di menu Import Gagal dan di halaman batch impor).
 */
class SalesImportFailuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('batch'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('batch.file_name')
                    ->label('File')
                    ->description(fn (SalesImportFailure $record): ?string => $record->batch?->created_at?->format('d/m/Y H:i'))
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('line')
                    ->label('Baris')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : 'File'),
                TextColumn::make('order_date')
                    ->label('Tanggal')
                    ->state(fn (SalesImportFailure $record): ?string => $record->value('TANGGAL'))
                    ->placeholder('-'),
                TextColumn::make('store')
                    ->label('Toko')
                    ->state(fn (SalesImportFailure $record): ?string => $record->value('NAMA TOKO'))
                    ->placeholder('-')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw('"values"::text ilike ?', ['%'.$search.'%'])),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->state(fn (SalesImportFailure $record): ?string => $record->value('SKU VARIAN') ?? $record->value('SKU BIG SELLER'))
                    ->placeholder('-'),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->state(fn (SalesImportFailure $record): ?string => $record->value('QTY'))
                    ->placeholder('-'),
                TextColumn::make('reason')
                    ->label('Kategori')
                    ->badge(),
                TextColumn::make('message')
                    ->label('Alasan gagal & cara memperbaiki')
                    ->description(fn (SalesImportFailure $record): string => $record->reason->hint())
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (SalesImportFailure $record): ?string => $record->resolved_at
                        ? $record->resolved_at->format('d/m/Y H:i').($record->resolved_batch_id ? " · batch #{$record->resolved_batch_id}" : '')
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ImportFailureStatus::class)
                    ->default(ImportFailureStatus::Open->value),
                SelectFilter::make('reason')
                    ->label('Kategori alasan')
                    ->options(ImportFailureReason::class)
                    ->multiple(),
                SelectFilter::make('batch_id')
                    ->label('File / batch')
                    ->relationship('batch', 'file_name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                Action::make('downloadFailures')
                    ->label('Unduh baris gagal (Excel)')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->tooltip('Sesuai filter tabel. Perbaiki kolom merah lalu import ulang file ini.')
                    ->action(fn (HasTable $livewire, SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download($spreadsheet->failures($livewire->getFilteredSortedTableQuery()), $spreadsheet->fileName('baris-gagal-pesanan', 'xlsx'))
                        ->deleteFileAfterSend()),
            ])
            ->recordActions([
                Action::make('correctSku')
                    ->label('Perbaiki SKU')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize(fn (): bool => ApplicationAccess::allows('kelola penjualan'))
                    ->visible(fn (SalesImportFailure $record): bool => $record->line > 0 && $record->status === ImportFailureStatus::Open)
                    ->modalHeading('Perbaiki SKU dan coba impor ulang')
                    ->modalDescription(fn (SalesImportFailure $record): string => 'Toko: '.$record->value('NAMA TOKO').' | Tanggal: '.$record->value('TANGGAL').' | Qty: '.$record->value('QTY').' | SKU awal: '.($record->value('SKU VARIAN') ?? $record->value('SKU BIG SELLER') ?? '-'))
                    ->modalSubmitActionLabel('Simpan dan coba ulang')
                    ->schema([
                        Select::make('sku')
                            ->label('SKU yang benar')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => ProductVariant::query()
                                ->where('is_active', true)
                                ->where(fn (Builder $query) => $query->where('sku', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%'))
                                ->orderBy('sku')->limit(50)->get()->mapWithKeys(fn (ProductVariant $variant): array => [$variant->sku => $variant->sku.' - '.$variant->name])->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ($variant = ProductVariant::query()->where('sku', $value)->where('is_active', true)->first()) ? $variant->sku.' - '.$variant->name : null)
                            ->helperText('Ketik SKU atau nama produk, lalu pilih varian. Koreksi hanya untuk baris ini; pemetaan SKU lainnya tetap.')
                            ->required(),
                        Select::make('warehouse_id')
                            ->label('Gudang asal')
                            ->options(fn (): array => Warehouse::goodsOptions())
                            ->default(fn () => Warehouse::defaultForGoods()?->id)
                            ->helperText('Pilih gudang yang digunakan pada impor awal.')
                            ->required(),
                        Select::make('status')
                            ->label('Status pesanan')
                            ->options([
                                SalesOrderStatus::Completed->value => SalesOrderStatus::Completed->getLabel(),
                                SalesOrderStatus::Pending->value => SalesOrderStatus::Pending->getLabel(),
                            ])
                            ->default(SalesOrderStatus::Completed->value)
                            ->required(),
                    ])
                    ->action(function (SalesImportFailure $record, array $data, SalesOrderImporter $importer): void {
                        $result = $importer->retryFailure($record, $data['sku'], ['warehouse_id' => (int) $data['warehouse_id'], 'status' => $data['status']]);
                        if ($result['errors'] !== []) {
                            Notification::make()->title('Baris masih gagal diimpor')->body(e(implode("\n", $result['errors'])))->danger()->persistent()->send();

                            return;
                        }
                        Notification::make()->title($result['created'] > 0 ? 'SKU diperbaiki dan pesanan berhasil disimpan' : 'Pesanan sudah tercatat; tidak ditambahkan lagi')->success()->send();
                    }),
                Action::make('ignore')
                    ->label('Abaikan')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->tooltip('Baris ini memang tidak perlu diimport (mis. pesanan uji / batal)')
                    ->visible(fn (SalesImportFailure $record): bool => $record->status === ImportFailureStatus::Open)
                    ->action(fn (SalesImportFailure $record) => $record->update(['status' => ImportFailureStatus::Ignored, 'resolved_at' => now()])),
                Action::make('reopen')
                    ->label('Buka lagi')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (SalesImportFailure $record): bool => $record->status === ImportFailureStatus::Ignored)
                    ->action(fn (SalesImportFailure $record) => $record->update(['status' => ImportFailureStatus::Open, 'resolved_at' => null])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('downloadSelected')
                        ->label('Unduh yang dipilih')
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->action(fn (Collection $records, SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                            ->download($spreadsheet->failures(SalesImportFailure::query()->whereKey($records->modelKeys())), $spreadsheet->fileName('baris-gagal-pesanan', 'xlsx'))
                            ->deleteFileAfterSend()),
                    BulkAction::make('ignoreSelected')
                        ->label('Abaikan yang dipilih')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => SalesImportFailure::query()
                            ->whereKey($records->modelKeys())
                            ->where('status', ImportFailureStatus::Open)
                            ->update(['status' => ImportFailureStatus::Ignored, 'resolved_at' => now()])),
                ]),
            ]);
    }
}
