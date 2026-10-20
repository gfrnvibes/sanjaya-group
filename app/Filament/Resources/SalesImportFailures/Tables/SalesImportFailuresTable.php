<?php

namespace App\Filament\Resources\SalesImportFailures\Tables;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Models\SalesImportFailure;
use App\Services\SalesOrderSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
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
