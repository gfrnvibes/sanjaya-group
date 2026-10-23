<?php

namespace App\Filament\Resources\SalesImportFailures\Tables;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Imports\ImportRegistry;
use App\Models\ProductVariant;
use App\Models\SalesImportFailure;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use App\Services\SkuMapping;
use App\Services\SkuMatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tabel Import Perlu Dicek (semua kategori data). Dipakai juga di halaman batch impor pesanan.
 */
class SalesImportFailuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('batch'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('importer')
                    ->label('Kategori data')
                    ->formatStateUsing(fn (string $state): string => ImportRegistry::label($state))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('file_name')
                    ->label('File / baris')
                    ->state(fn (SalesImportFailure $record): string => ($record->file_name ?? $record->batch?->file_name ?? '-').($record->line > 0 ? " · baris {$record->line}" : ' · file'))
                    ->description(fn (SalesImportFailure $record): ?string => $record->created_at?->format('d/m/Y H:i'))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('item')
                    ->label('Nama barang / data')
                    ->state(fn (SalesImportFailure $record): string => $record->itemName() ?? self::summary($record, 2) ?? '-')
                    ->description(fn (SalesImportFailure $record): ?string => self::details($record))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw('"values"::text ilike ?', ['%'.$search.'%'])),
                TextColumn::make('suggestion')
                    ->label('Saran SKU')
                    ->state(fn (SalesImportFailure $record): ?string => self::canMapSku($record)
                        ? (($best = $record->skuSuggestions(1)[0] ?? null)
                            ? $best['variant']->sku.' ('.$best['score'].'%)'
                            : 'Tidak ada saran')
                        : null)
                    ->description(fn (SalesImportFailure $record): ?string => self::canMapSku($record)
                        ? (($best = $record->skuSuggestions(1)[0] ?? null) ? $best['variant']->name : null)
                        : null)
                    ->color(fn (?string $state): string => preg_match('/\((\d+)%\)/', (string) $state, $match) && (int) $match[1] >= SkuMatcher::HIGH_CONFIDENCE ? 'success' : 'warning')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('reason')
                    ->label('Kategori alasan')
                    ->badge(),
                TextColumn::make('message')
                    ->label('Alasan & cara memperbaiki')
                    ->description(fn (SalesImportFailure $record): string => $record->reason->hint())
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (SalesImportFailure $record): ?string => $record->resolved_at?->format('d/m/Y H:i')),
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
                SelectFilter::make('importer')
                    ->label('Kategori data')
                    ->options(fn (): array => collect(ImportRegistry::accessibleKeys())->mapWithKeys(fn (string $key): array => [$key => ImportRegistry::label($key)])->all())
                    ->multiple(),
                SelectFilter::make('batch_id')
                    ->label('Batch pesanan')
                    ->relationship('batch', 'file_name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                Action::make('downloadFailures')
                    ->label('Unduh baris gagal (Excel)')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (HasTable $livewire): bool => $livewire instanceof RelationManager)
                    ->action(fn (HasTable $livewire, SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download($spreadsheet->failures($livewire->getFilteredSortedTableQuery()), $spreadsheet->fileName('baris-gagal-pesanan', 'xlsx'))
                        ->deleteFileAfterSend()),
            ])
            ->recordActions([
                self::mapSkuAction(),
                self::editAction(),
                ActionGroup::make([
                    self::retryAction(),
                    self::correctSkuAction(),
                    Action::make('ignore')
                        ->label('Abaikan')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('gray')
                        ->tooltip('Baris ini memang tidak perlu diimport (mis. data uji / pesanan batal)')
                        ->visible(fn (SalesImportFailure $record): bool => $record->status === ImportFailureStatus::Open && ImportRegistry::allows($record->importer))
                        ->action(fn (SalesImportFailure $record) => $record->update(['status' => ImportFailureStatus::Ignored, 'resolved_at' => now()])),
                    Action::make('reopen')
                        ->label('Buka lagi')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->visible(fn (SalesImportFailure $record): bool => $record->status === ImportFailureStatus::Ignored && ImportRegistry::allows($record->importer))
                        ->action(fn (SalesImportFailure $record) => $record->update(['status' => ImportFailureStatus::Open, 'resolved_at' => null])),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retrySelected')
                        ->label('Coba ulang yang dipilih')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalDescription('Pakai setelah master data dibenahi (mis. toko/varian/gudang sudah didaftarkan). Baris dicoba ulang per kategori dengan isi yang sama.')
                        ->schema(fn (): array => [
                            Section::make('Khusus pesanan')->schema(ListSalesImportFailures::categoryOptionFields(ImportRegistry::SALES_ORDERS))->compact(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $totals = ['fixed' => 0, 'failed' => 0, 'errors' => []];

                            foreach ($records->where('status', ImportFailureStatus::Open)->groupBy('importer') as $key => $failures) {
                                if (! ImportRegistry::allows($key)) {
                                    continue;
                                }

                                $result = self::safeRetry($key, $failures, [], ListSalesImportFailures::categoryOptions($key, $data));
                                $totals['fixed'] += $result['fixed'];
                                $totals['failed'] += $result['failed'];
                                array_push($totals['errors'], ...$result['errors']);
                            }

                            self::notifyRetry($totals);
                        }),
                    BulkAction::make('downloadSelected')
                        ->label('Unduh template yang dipilih')
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->action(function (Collection $records): ?BinaryFileResponse {
                            $keys = $records->pluck('importer')->unique();

                            if ($keys->count() !== 1) {
                                Notification::make()->title('Pilih baris dari 1 kategori data saja')->body('Template perbaikan dibuat per kategori. Buka tab kategorinya lalu pilih barisnya.')->warning()->send();

                                return null;
                            }

                            $key = $keys->first();

                            return response()
                                ->download(ImportRegistry::fixTemplate($key, SalesImportFailure::query()->whereKey($records->modelKeys())), 'perbaikan-'.Str::slug(ImportRegistry::label($key)).'-'.now()->format('Ymd-His').'.xlsx')
                                ->deleteFileAfterSend();
                        }),
                    BulkAction::make('ignoreSelected')
                        ->label('Abaikan yang dipilih')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => SalesImportFailure::query()
                            ->whereKey($records->filter(fn (SalesImportFailure $record): bool => ImportRegistry::allows($record->importer))->modelKeys())
                            ->where('status', ImportFailureStatus::Open)
                            ->update(['status' => ImportFailureStatus::Ignored, 'resolved_at' => now()])),
                ]),
            ]);
    }

    /**
     * Edit langsung: isi kolom merah baris ini lalu coba ulang (baris lain di dokumen yang sama ikut dicoba ulang).
     */
    private static function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (SalesImportFailure $record): bool => self::isRetryable($record))
            ->modalHeading(fn (SalesImportFailure $record): string => 'Perbaiki '.ImportRegistry::label($record->importer).' — baris '.$record->line)
            ->modalDescription(fn (SalesImportFailure $record): string => $record->message.' '.$record->reason->hint())
            ->modalSubmitActionLabel('Simpan & coba ulang')
            ->fillForm(fn (SalesImportFailure $record): array => collect(ImportRegistry::editableLabels($record->importer))
                ->mapWithKeys(fn (string $label, int $index): array => ["field_{$index}" => $record->value($label)])
                ->all())
            ->schema(fn (SalesImportFailure $record): array => [
                Grid::make(2)->schema(collect(ImportRegistry::editableLabels($record->importer))
                    ->map(fn (string $label, int $index): TextInput => TextInput::make("field_{$index}")
                        ->label($label)
                        ->hint(self::mentioned($record, $label) ? 'perlu diperbaiki' : null)
                        ->hintColor('danger'))
                    ->all()),
                ...ListSalesImportFailures::categoryOptionFields($record->importer === ImportRegistry::SALES_ORDERS ? ImportRegistry::SALES_ORDERS : null),
            ])
            ->action(function (SalesImportFailure $record, array $data): void {
                $edited = collect(ImportRegistry::editableLabels($record->importer))
                    ->mapWithKeys(fn (string $label, int $index): array => [$label => trim((string) ($data["field_{$index}"] ?? ''))])
                    ->all();

                self::notifyRetry(self::safeRetry($record->importer, self::documentRows($record), [$record->id => $edited], ListSalesImportFailures::categoryOptions($record->importer, $data)));
            });
    }

    /**
     * Coba ulang tanpa mengubah isi (setelah master data diperbaiki).
     */
    private static function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Coba ulang')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (SalesImportFailure $record): bool => self::isRetryable($record))
            ->modalHeading('Coba ulang baris ini')
            ->modalDescription('Pakai setelah master data yang kurang sudah dilengkapi (toko, varian, harga, gudang, dll.).')
            ->schema(fn (SalesImportFailure $record): array => ListSalesImportFailures::categoryOptionFields($record->importer === ImportRegistry::SALES_ORDERS ? ImportRegistry::SALES_ORDERS : null))
            ->action(fn (SalesImportFailure $record, array $data) => self::notifyRetry(self::safeRetry($record->importer, self::documentRows($record), [], ListSalesImportFailures::categoryOptions($record->importer, $data))));
    }

    /**
     * PETAKAN SKU (pintar): saran varian dari kode model, warna, ukuran & nama produk; pemetaan disimpan
     * lalu semua baris dengan SKU marketplace yang sama ikut dicoba ulang.
     */
    private static function mapSkuAction(): Action
    {
        return Action::make('mapSku')
            ->label('Petakan SKU')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->visible(fn (SalesImportFailure $record): bool => self::canMapSku($record) && ImportRegistry::allows($record->importer))
            ->modalHeading(fn (SalesImportFailure $record): string => 'Petakan SKU "'.$record->rawSku().'"')
            ->modalSubmitActionLabel('Petakan & coba ulang')
            ->schema(function (SalesImportFailure $record): array {
                $suggestions = $record->skuSuggestions();
                $sameSku = $record->value('SKU BIG SELLER') === null ? 1 : app(SkuMapping::class)->openSkuFailures()
                    ->whereRaw("lower(\"values\"->>'SKU BIG SELLER') = ?", [Str::lower($record->value('SKU BIG SELLER'))])->count();

                return [
                    Text::make(new HtmlString(
                        '<b>Nama barang:</b> '.e($record->itemName() ?? '-').($record->value('NAMA VARIASI') ? ' · <b>Variasi:</b> '.e($record->value('NAMA VARIASI')) : '').'<br>'
                        .'<b>SKU marketplace:</b> '.e($record->rawSku() ?? '-').' · <b>Toko:</b> '.e($record->value('NAMA TOKO') ?? '-')
                        .' · <b>Qty:</b> '.e($record->value('QTY') ?? '-').' · <b>Tanggal:</b> '.e($record->value('TANGGAL') ?? '-')
                        .($sameSku > 1 ? "<br>{$sameSku} baris lain/termasuk ini memakai SKU yang sama dan ikut diperbaiki." : '')
                    )),
                    Radio::make('suggestion')
                        ->label('Saran sistem (kode model, warna, ukuran dari SKU, nama variasi & nama produk)')
                        ->options(collect($suggestions)->mapWithKeys(fn (array $suggestion): array => [$suggestion['variant']->id => app(SkuMatcher::class)->label($suggestion)])->all())
                        ->default(($suggestions[0]['score'] ?? 0) >= SkuMatcher::HIGH_CONFIDENCE ? $suggestions[0]['variant']->id : null)
                        ->visible($suggestions !== [])
                        ->live(),
                    Select::make('variant_id')
                        ->label($suggestions === [] ? 'Pilih varian' : 'Atau pilih varian lain (pencocokan manual)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchVariants($search))
                        ->getOptionLabelUsing(fn ($value): ?string => ($variant = ProductVariant::find($value)) ? $variant->sku.' - '.$variant->name : null)
                        ->required(fn (Get $get): bool => blank($get('suggestion')))
                        ->helperText('Ketik SKU atau nama produk. Kosongkan bila memakai saran di atas.'),
                    Toggle::make('save_alias')
                        ->label('Simpan sebagai Pemetaan SKU (import berikutnya otomatis cocok)')
                        ->default($record->value('SKU BIG SELLER') !== null)
                        ->disabled($record->value('SKU BIG SELLER') === null)
                        ->helperText($record->value('SKU BIG SELLER') === null ? 'Baris ini memakai kolom SKU VARIAN, jadi hanya baris ini yang dikoreksi.' : null),
                    ...ListSalesImportFailures::categoryOptionFields(ImportRegistry::SALES_ORDERS),
                ];
            })
            ->action(function (SalesImportFailure $record, array $data, SkuMapping $mapping): void {
                $variant = ProductVariant::find($data['variant_id'] ?? null) ?? ProductVariant::find($data['suggestion'] ?? null);
                $options = ListSalesImportFailures::categoryOptions(ImportRegistry::SALES_ORDERS, $data);

                if ($variant === null) {
                    Notification::make()->title('Pilih varian terlebih dahulu')->danger()->send();

                    return;
                }

                try {
                    if (($data['save_alias'] ?? false) && $record->value('SKU BIG SELLER') !== null) {
                        $mapping->map($record->value('SKU BIG SELLER'), $variant);
                        $result = $mapping->retryAlias($record->value('SKU BIG SELLER'), $options);
                    } else {
                        $result = ImportRegistry::retry(ImportRegistry::SALES_ORDERS, collect([$record]), [$record->id => ['SKU VARIAN' => $variant->sku]], $options);
                    }
                } catch (BusinessRuleException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                self::notifyRetry($result, "SKU dipetakan ke {$variant->sku}");
            });
    }

    /**
     * Koreksi SKU 1 baris tanpa menyimpan pemetaan (perilaku lama, tetap tersedia).
     */
    private static function correctSkuAction(): Action
    {
        return Action::make('correctSku')
            ->label('Koreksi SKU baris ini saja')
            ->icon(Heroicon::OutlinedPencil)
            ->visible(fn (SalesImportFailure $record): bool => $record->importer === ImportRegistry::SALES_ORDERS && self::isRetryable($record))
            ->modalHeading('Perbaiki SKU dan coba impor ulang')
            ->modalDescription(fn (SalesImportFailure $record): string => 'Barang: '.($record->itemName() ?? '-').($record->value('NAMA VARIASI') ? ' ('.$record->value('NAMA VARIASI').')' : '').' | Toko: '.$record->value('NAMA TOKO').' | Tanggal: '.$record->value('TANGGAL').' | Qty: '.$record->value('QTY').' | SKU awal: '.($record->rawSku() ?? '-'))
            ->modalSubmitActionLabel('Simpan dan coba ulang')
            ->schema([
                Select::make('sku')
                    ->label('SKU yang benar')
                    ->searchable()
                    ->options(fn (SalesImportFailure $record): array => collect($record->skuSuggestions())
                        ->mapWithKeys(fn (array $suggestion): array => [$suggestion['variant']->sku => app(SkuMatcher::class)->label($suggestion)])
                        ->all())
                    ->getSearchResultsUsing(fn (string $search): array => collect(self::searchVariants($search))
                        ->mapWithKeys(fn (string $label, int $id): array => [Str::before($label, ' - ') => $label])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($variant = ProductVariant::query()->where('sku', $value)->where('is_active', true)->first()) ? $variant->sku.' - '.$variant->name : null)
                    ->helperText('Saran sistem tampil di atas; ketik SKU atau nama produk untuk mencari. Koreksi hanya untuk baris ini; pemetaan SKU lainnya tetap.')
                    ->required(),
                ...ListSalesImportFailures::categoryOptionFields(ImportRegistry::SALES_ORDERS),
            ])
            ->action(function (SalesImportFailure $record, array $data, SalesOrderImporter $importer): void {
                $result = $importer->retryFailure($record, $data['sku'], ['warehouse_id' => (int) $data['warehouse_id'], 'status' => $data['status']]);
                if ($result['errors'] !== []) {
                    Notification::make()->title('Baris masih gagal diimpor')->body(e(implode("\n", $result['errors'])))->danger()->persistent()->send();

                    return;
                }
                Notification::make()->title($result['created'] > 0 ? 'SKU diperbaiki dan pesanan berhasil disimpan' : 'Pesanan sudah tercatat; tidak ditambahkan lagi')->success()->send();
            });
    }

    // ---------------------------------------------------------------- bantuan

    private static function isRetryable(SalesImportFailure $record): bool
    {
        return $record->line > 0 && $record->status === ImportFailureStatus::Open && ImportRegistry::allows($record->importer);
    }

    private static function canMapSku(SalesImportFailure $record): bool
    {
        return $record->importer === ImportRegistry::SALES_ORDERS
            && $record->reason === ImportFailureReason::SkuNotFound
            && $record->status === ImportFailureStatus::Open
            && $record->line > 0
            && $record->rawSku() !== null;
    }

    /**
     * Baris terbuka lain dari dokumen yang sama (mis. 1 nota barang masuk) ikut dicoba ulang.
     *
     * @return \Illuminate\Support\Collection<int, SalesImportFailure>
     */
    private static function documentRows(SalesImportFailure $record): \Illuminate\Support\Collection
    {
        if ($record->group_key === null) {
            return collect([$record]);
        }

        return SalesImportFailure::query()
            ->open()
            ->where('importer', $record->importer)
            ->where('group_key', $record->group_key)
            ->orderBy('line')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesImportFailure>  $failures
     * @param  array<int, array<string, mixed>>  $edited
     * @param  array<string, mixed>  $options
     * @return array{fixed: int, failed: int, errors: list<string>}
     */
    private static function safeRetry(string $key, \Illuminate\Support\Collection $failures, array $edited, array $options): array
    {
        try {
            return ImportRegistry::retry($key, $failures, $edited, $options);
        } catch (BusinessRuleException $exception) {
            return ['fixed' => 0, 'failed' => $failures->count(), 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * @param  array{fixed: int, failed: int, errors: list<string>}  $result
     */
    private static function notifyRetry(array $result, ?string $prefix = null): void
    {
        $title = ($prefix ? $prefix.': ' : '').match (true) {
            $result['failed'] === 0 && $result['fixed'] > 0 => "{$result['fixed']} baris berhasil disimpan",
            $result['fixed'] > 0 => "{$result['fixed']} baris berhasil, {$result['failed']} masih gagal",
            default => 'Baris masih gagal',
        };

        Notification::make()
            ->title($title)
            ->body($result['errors'] === [] ? null : e(implode("\n", array_slice($result['errors'], 0, 8))).($result['failed'] > 0 ? "\nAlasan terbaru tampil di daftar." : ''))
            ->{$result['failed'] === 0 && $result['fixed'] > 0 ? 'success' : ($result['fixed'] > 0 ? 'warning' : 'danger')}()
            ->send();
    }

    /**
     * Ringkasan isi baris: "LABEL: nilai · ...".
     */
    private static function summary(SalesImportFailure $record, int $limit): ?string
    {
        $text = collect(ImportRegistry::exists($record->importer) ? ImportRegistry::editableLabels($record->importer) : array_keys($record->values ?? []))
            ->map(fn (string $label): ?string => ($value = $record->value($label)) === null ? null : "{$label}: ".Str::limit($value, 40))
            ->filter()
            ->take($limit)
            ->implode(' · ');

        return $text === '' ? null : $text;
    }

    private static function details(SalesImportFailure $record): ?string
    {
        if ($record->importer === ImportRegistry::SALES_ORDERS) {
            return collect([
                'SKU' => $record->rawSku(),
                'Toko' => $record->value('NAMA TOKO'),
                'Tgl' => $record->value('TANGGAL'),
                'Qty' => $record->value('QTY'),
            ])->filter()->map(fn (string $value, string $label): string => "{$label}: {$value}")->implode(' · ') ?: null;
        }

        return $record->itemName() === null ? self::summary($record, 6) : self::summary($record, 4);
    }

    private static function mentioned(SalesImportFailure $record, string $label): bool
    {
        return str_contains(Str::upper($record->message), $label);
    }

    /**
     * @return array<int, string>
     */
    private static function searchVariants(string $search): array
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('sku', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%'))
            ->orderBy('sku')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->sku.' - '.$variant->name])
            ->all();
    }
}
