<?php

namespace App\Filament\Resources\VariantPriceComponents;

use App\Enums\ProductKind;
use App\Enums\WageType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\VariantPriceComponents\Pages\ListPriceComponents;
use App\Filament\Support\PriceSyncNotification;
use App\Models\ProductVariant;
use App\Services\CostingService;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Support\ApplicationAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * KOMPONEN HARGA: semua komponen harga per varian — HPP (resep BOM: bahan & ongkos, atau beli jadi: harga beli &
 * packing), margin, harga dasar berlaku/usulan, dan harga reseller per tier.
 * Turunannya: Produk Produksi & Produk Beli Jadi (tabel sama, disaring per jenis model).
 * HPP = bahan/harga beli + ongkos + packing; packing punya riwayat sendiri (berlaku mulai).
 * Yang bisa diubah: MARGIN & PACKING (selalu dengan tanggal BERLAKU MULAI); HPP produksi hanya dari resep (BOM),
 * HPP beli jadi dari harga beli terakhir. Khusus beli jadi, harga dasar juga boleh diisi manual.: pesanan lama tidak berubah
 * (harga tersimpan per pesanan), pesanan bertanggal >= tanggal berlaku memakai harga baru.
 */
class PriceComponentResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    /** Null = semua jenis; diisi oleh turunan (produksi / beli jadi). */
    protected static ?ProductKind $kind = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = -1; // tepat di bawah Dashboard

    protected static ?string $navigationLabel = 'Komponen Harga';

    protected static ?string $modelLabel = 'varian';

    protected static ?string $pluralModelLabel = 'Komponen Harga';

    protected static ?string $slug = 'komponen-harga';

    protected static bool $isGloballySearchable = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEditPrices(): bool
    {
        return ApplicationAccess::allows('Update:ProductVariant');
    }

    public static function kind(): ?ProductKind
    {
        return static::$kind;
    }

    /**
     * @return Builder<ProductVariant>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(static::$kind, fn (Builder $query, ProductKind $kind) => $query->whereHas('model', fn (Builder $query) => $query->where('kind', $kind)));
    }

    public static function table(Table $table): Table
    {
        $kind = static::$kind;
        $readyMade = $kind === ProductKind::BeliJadi;
        $produced = $kind === ProductKind::Produksi;
        $breakdown = app(PriceBreakdown::class);
        $money = fn (TextColumn $column): TextColumn => $column->money('IDR', decimalPlaces: 0)->alignEnd()->placeholder('-');
        $percent = fn ($value): ?string => $value === null ? null : rtrim(rtrim(number_format((float) $value * 100, 2, ',', '.'), '0'), ',').'%';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'model.category', 'color', 'size',
                'costs' => fn ($query) => $query->orderByDesc('effective_from'),
                'priceHistories' => fn ($query) => $query->orderByDesc('effective_from'),
            ]))
            ->defaultSort('sku')
            ->striped()
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama varian')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('model.category.name')
                    ->label('Kategori')
                    ->toggleable(),
                TextColumn::make('model.code')
                    ->label('Model')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model.kind')
                    ->label('Jenis')
                    ->badge()
                    ->visible($kind === null)
                    ->toggleable(),
                TextColumn::make('bom')
                    ->label('Resep (BOM)')
                    ->state(fn (ProductVariant $record): ?string => $record->isReadyMade() ? null : $breakdown->bomSummary($record))
                    ->placeholder('Belum ada resep')
                    ->wrap()
                    ->visible($produced)
                    ->toggleable(),
                TextColumn::make('supplier')
                    ->label('Supplier')
                    ->state(fn (ProductVariant $record): ?string => $breakdown->supplierName($record))
                    ->placeholder('-')
                    ->tooltip('Supplier lot Barang Masuk berikutnya (FIFO)')
                    ->visible(! $produced)
                    ->toggleable(),
                TextColumn::make('wholesale')
                    ->label('Harga grosir bordir')
                    ->state(fn (ProductVariant $record): ?string => $breakdown->wholesaleText($record))
                    ->placeholder('-')
                    ->wrap()
                    ->tooltip('Titipan: harga ke reseller = harga supplier bila total qty SKU yang sama dalam 1 pesanan >= min. qty')
                    ->visible(! $produced)
                    ->toggleable(isToggledHiddenByDefault: ! $readyMade),
                TextColumn::make('color.name')
                    ->label('Warna')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size.code')
                    ->label('Ukuran')
                    ->toggleable(isToggledHiddenByDefault: true),
                ColumnGroup::make('HPP (berlaku hari ini)', [
                    $money(TextColumn::make('material_cost')
                        ->label(match ($kind) {
                            ProductKind::BeliJadi => 'Harga beli',
                            ProductKind::Produksi => 'Bahan',
                            default => 'Bahan / harga beli',
                        })
                        ->state(fn (ProductVariant $record): ?float => ($cost = $breakdown->cost($record)) ? (float) $cost->material_cost : null)
                        ->tooltip(fn (ProductVariant $record): string => $record->isReadyMade() ? 'Harga beli terakhir (Barang Masuk sumber Beli Jadi)' : 'Bahan dari resep (BOM) x harga beli bahan')
                        ->toggleable()),
                    $money(TextColumn::make('labor_cost')
                        ->label('Ongkos')
                        ->state(fn (ProductVariant $record): ?float => ($cost = $breakdown->cost($record)) && ! $record->isReadyMade() ? (float) $cost->labor_cost - (float) $cost->packing_cost : null)
                        ->tooltip('Ongkos kerja dari resep (BOM)')
                        ->visible(! $readyMade)
                        ->toggleable()),
                    $money(TextColumn::make('hpp_bom')
                        ->label('HPP resep (BOM)')
                        ->state(fn (ProductVariant $record): ?float => ($cost = $breakdown->cost($record)) && ! $record->isReadyMade() ? (float) $cost->total_cost - (float) $cost->packing_cost : null)
                        ->tooltip('Bahan + ongkos dari resep (BOM). Tidak bisa diisi manual: ubah resep atau harga beli bahan.')
                        ->visible($produced)
                        ->toggleable()),
                    $money(TextColumn::make('packing')
                        ->label('Packing')
                        ->state(fn (ProductVariant $record): ?float => ($cost = $breakdown->cost($record)) ? (float) $cost->packing_cost : null)
                        ->description(fn (ProductVariant $record): ?string => $breakdown->packingNote($record))
                        ->tooltip('Biaya packing per pcs (bisa diubah lewat Ubah harga, dengan tanggal berlaku)')
                        ->toggleable()),
                    $money(TextColumn::make('hpp')
                        ->label('HPP + packing')
                        ->state(fn (ProductVariant $record): ?float => ($cost = $breakdown->cost($record)) ? (float) $cost->total_cost : null)
                        ->weight('bold')
                        ->toggleable()),
                ]),
                ColumnGroup::make('Margin', [
                    TextColumn::make('effective_margin')
                        ->label('Margin')
                        ->state(fn (ProductVariant $record): string => $breakdown->marginText($record))
                        ->description(fn (ProductVariant $record): ?string => ($price = $breakdown->price($record)) && $price->margin_pct !== null
                            ? 'pada harga berlaku: '.$percent($price->margin_pct)
                            : null)
                        ->toggleable(),
                ]),
                ColumnGroup::make('Harga dasar', [
                    $money(TextColumn::make('base_price')
                        ->label('Berlaku')
                        ->state(fn (ProductVariant $record): ?float => ($price = $breakdown->price($record)) ? (float) $price->base_price : null)
                        ->description(fn (ProductVariant $record): ?string => ($price = $breakdown->price($record))
                            ? 'mulai '.$price->effective_from->format('d/m/Y').($price->source?->value === 'manual' ? ' · manual' : '')
                            : null)
                        ->weight('bold')
                        ->toggleable()),
                    $money(TextColumn::make('next_price')
                        ->label('Terjadwal')
                        ->state(fn (ProductVariant $record): ?float => ($next = $record->priceHistories->first(fn ($price): bool => $price->effective_from->gt(today()))) ? (float) $next->base_price : null)
                        ->description(fn (ProductVariant $record): ?string => $record->priceHistories->filter(fn ($price): bool => $price->effective_from->gt(today()))->last()?->effective_from?->format('d/m/Y'))
                        ->tooltip('Harga yang sudah disimpan untuk tanggal mendatang')
                        ->toggleable()),
                    $money(TextColumn::make('proposed_price')
                        ->label('Usulan')
                        ->state(fn (ProductVariant $record): ?float => $breakdown->proposed($record))
                        ->color(fn (ProductVariant $record): ?string => ($proposed = $breakdown->proposed($record)) !== null
                            && ($price = $breakdown->price($record)) !== null && round($proposed, 2) !== round((float) $price->base_price, 2) ? 'warning' : null)
                        ->tooltip('HPP berlaku x margin saat ini. Berbeda dengan harga berlaku = gunakan Ubah harga / Hitung ulang.')
                        ->toggleable(isToggledHiddenByDefault: true)),
                ]),
                ColumnGroup::make('Harga reseller per tier', $breakdown->tiers()
                    ->map(fn ($tier): TextColumn => $money(TextColumn::make("tier_{$tier->id}")
                        ->label($tier->name.' ('.($tier->adjustment_pct >= 0 ? '+' : '').rtrim(rtrim(number_format((float) $tier->adjustment_pct * 100, 2, ',', '.'), '0'), ',').'%)')
                        ->state(fn (ProductVariant $record): ?float => $breakdown->tierPrice($record, $tier))
                        ->toggleable(isToggledHiddenByDefault: $breakdown->tiers()->count() > 4)))
                    ->all()),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('model.category', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Jenis produk')
                    ->options(ProductKind::class)
                    ->visible($kind === null)
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $kind) => $query->whereHas('model', fn (Builder $query) => $query->where('kind', $kind)))),
                SelectFilter::make('model_id')
                    ->label('Model')
                    ->relationship('model', 'name', fn (Builder $query) => $query->when($kind, fn (Builder $query) => $query->where('kind', $kind)))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('price_status')
                    ->label('Status harga')
                    ->options([
                        'missing' => 'Belum ada harga',
                        'outdated' => 'Berbeda dengan usulan',
                        'current' => 'Sesuai usulan',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'missing' => $query->whereDoesntHave('priceHistories', fn (Builder $query) => $query->whereDate('effective_from', '<=', today())),
                        'outdated' => $query->whereIn('product_variants.id', DB::table('v_variant_pricing')->whereColumn('proposed_price', '!=', 'current_price')->select('variant_id')),
                        'current' => $query->whereIn('product_variants.id', DB::table('v_variant_pricing')->whereColumn('proposed_price', '=', 'current_price')->select('variant_id')),
                        default => $query,
                    }),
                SelectFilter::make('wage_check')
                    ->label('Ongkos upah di resep')
                    ->options([
                        'no_sewing' => 'Resep tanpa ongkos jahit',
                        'no_cutting' => 'Resep tanpa ongkos potong',
                    ])
                    ->visible(! $readyMade)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'no_sewing', 'no_cutting' => $query
                            ->whereHas('model', fn (Builder $query) => $query->where('kind', ProductKind::Produksi))
                            ->whereDoesntHave('model.boms.items', fn (Builder $query) => $query
                                ->where('line_type', 'labor')
                                ->whereHas('laborType', fn (Builder $query) => $query->where('wage_type', $data['value'] === 'no_sewing' ? WageType::Jahit : WageType::Potong))),
                        default => $query,
                    }),
                TernaryFilter::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                self::changePriceAction(),
                Action::make('detail')
                    ->label('Rincian')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->modalHeading(fn (ProductVariant $record): string => "Rincian harga {$record->sku} — {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (ProductVariant $record) => view('filament.price-components.detail', [
                        'variant' => $record,
                        'detail' => $breakdown->detail($record),
                        'cost' => $breakdown->cost($record),
                        'price' => $breakdown->price($record),
                        'margin' => $breakdown->marginText($record),
                        'proposed' => $breakdown->proposed($record),
                        'tiers' => $breakdown->tiers()->map(fn ($tier): array => ['name' => $tier->name, 'price' => $breakdown->tierPrice($record, $tier)])->all(),
                        'history' => $breakdown->history($record),
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkChangePrice')
                        ->label('Ubah margin / packing')
                        ->icon(Heroicon::OutlinedReceiptPercent)
                        ->visible(fn (): bool => self::canEditPrices())
                        ->modalDescription('Harga dasar baru dihitung otomatis (HPP x margin) mulai tanggal berlaku. Pesanan sebelum tanggal itu tidak berubah.')
                        ->schema([
                            TextInput::make('margin')
                                ->label('Margin khusus %')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('%')
                                ->helperText('Kosongkan = tidak diubah.'),
                            TextInput::make('packing')
                                ->label('Biaya packing per pcs')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('Rp')
                                ->helperText('Kosongkan = tidak diubah.'),
                            DatePicker::make('effective_from')
                                ->label('Berlaku mulai')
                                ->default(today())
                                ->required(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, PricingService $pricing): void {
                            abort_unless(self::canEditPrices(), 403);
                            $result = ['synced' => 0, 'skipped' => []];

                            foreach ($records as $record) {
                                $attributes = array_filter([
                                    'margin_pct' => blank($data['margin'] ?? null) ? null : round((float) $data['margin'] / 100, 4),
                                    'packing_cost' => blank($data['packing'] ?? null) ? null : round((float) $data['packing'], 2),
                                ], fn ($value): bool => $value !== null);

                                try {
                                    $pricing->changePrice($record, $attributes, $data['effective_from']);
                                    $result['synced']++;
                                } catch (BusinessRuleException $exception) {
                                    $result['skipped'][] = "{$record->sku}: ".rtrim($exception->getMessage(), '.').'.';
                                }
                            }

                            PriceSyncNotification::send($result);
                        }),
                    BulkAction::make('resyncSelected')
                        ->label('Hitung ulang harga')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (): bool => self::canEditPrices())
                        ->schema([
                            DatePicker::make('effective_from')->label('Berlaku mulai')->default(today())->required(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records, array $data, PricingService $pricing) => PriceSyncNotification::send($pricing->syncVariants($records, $data['effective_from']))),
                ]),
            ]);
    }

    /**
     * UBAH HARGA 1 varian dengan BERLAKU MULAI: margin khusus & biaya packing (harga dasar otomatis);
     * khusus beli jadi boleh harga dasar manual.
     */
    private static function changePriceAction(): Action
    {
        $breakdown = app(PriceBreakdown::class);
        $money = fn ($value): string => $value === null ? '-' : 'Rp '.number_format((float) $value, 0, ',', '.');

        return Action::make('changePrice')
            ->label('Ubah harga')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (): bool => self::canEditPrices())
            ->modalHeading(fn (ProductVariant $record): string => "Ubah harga {$record->sku}")
            ->modalSubmitActionLabel('Simpan harga baru')
            ->fillForm(fn (ProductVariant $record): array => [
                'margin' => $record->margin_pct === null ? null : round((float) $record->margin_pct * 100, 2),
                'packing' => app(CostingService::class)->packingCostFor($record),
                'effective_from' => today()->toDateString(),
            ])
            ->schema(fn (ProductVariant $record): array => [
                Text::make(new HtmlString(
                    '<b>'.e($record->name).'</b><br>'
                    .'HPP berlaku: '.$money($breakdown->cost($record)?->total_cost)
                    .' · Margin: '.e($breakdown->marginText($record))
                    .' · Harga dasar berlaku: '.$money($breakdown->price($record)?->base_price)
                    .($breakdown->price($record) ? ' (mulai '.$breakdown->price($record)->effective_from->format('d/m/Y').')' : '')
                )),
                TextInput::make('margin')
                    ->label('Margin khusus %')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('%')
                    ->placeholder(fn (): string => 'default '.rtrim(rtrim(number_format(app(PricingService::class)->marginFor((clone $record)->fill(['margin_pct' => null])) * 100, 2, ',', '.'), '0'), ',').'%')
                    ->helperText('Kosongkan = ikut margin model / pengaturan.'),
                TextInput::make('packing')
                    ->label('Biaya packing per pcs')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->placeholder('default '.number_format(app(CostingService::class)->defaultPackingFor($record), 0, ',', '.'))
                    ->helperText('Packing baru berlaku mulai tanggal di bawah. Kosongkan = kembali ke default pengaturan.'),
                TextInput::make('manual_price')
                    ->label('Harga dasar manual (opsional)')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->visible($record->isReadyMade())
                    ->helperText('Khusus beli jadi. Kosongkan = otomatis dari (harga beli + packing) x margin. Diisi = harga dasar ini dipakai apa adanya sampai ada Barang Masuk dengan harga beli baru.'),
                TextInput::make('note')
                    ->label('Catatan harga manual')
                    ->maxLength(255)
                    ->visible($record->isReadyMade()),
                DatePicker::make('effective_from')
                    ->label('Berlaku mulai')
                    ->required()
                    ->helperText('Pesanan sebelum tanggal ini tetap memakai harga lama (harga tersimpan per pesanan). Pesanan mulai tanggal ini memakai harga baru.'),
            ])
            ->action(function (ProductVariant $record, array $data, PricingService $pricing): void {
                abort_unless(self::canEditPrices(), 403);

                $attributes = [
                    'margin_pct' => blank($data['margin'] ?? null) ? null : round((float) $data['margin'] / 100, 4),
                    'packing_cost' => blank($data['packing'] ?? null) ? null : round((float) $data['packing'], 2),
                ];

                try {
                    $price = $pricing->changePrice($record, $attributes, $data['effective_from'], $record->isReadyMade() && filled($data['manual_price'] ?? null) ? (float) $data['manual_price'] : null, $data['note'] ?? null);
                } catch (BusinessRuleException $exception) {
                    Notification::make()->title('Harga tidak diubah')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("Harga {$record->sku} disimpan")
                    ->body('Harga dasar Rp '.number_format((float) $price->base_price, 0, ',', '.').' berlaku mulai '.Carbon::parse($price->effective_from)->format('d/m/Y').'. Pesanan sebelumnya tidak berubah.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceComponents::route('/'),
        ];
    }
}
