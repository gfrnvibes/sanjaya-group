<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SupplierBillLines\SupplierBillLineResource;
use App\Models\CashAccount;
use App\Models\Supplier;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use App\Services\SupplierBilling;
use App\Services\SupplierStatement;
use App\Support\ApplicationAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * TAGIHAN SUPPLIER beli jadi per periode, 1 saldo per supplier:
 *  - tab Ringkasan: saldo awal + Tagihan Pembelian + Tagihan Barang Keluar + titipan grosir - dibayar = sisa tagihan;
 *  - tab Tagihan Pembelian: Barang Masuk Beli Jadi ber-supplier (stok masuk konveksi);
 *  - tab Tagihan Barang Keluar: penjualan yang dikirim langsung supplier karena stok konveksi tidak cukup.
 */
class SupplierBills extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Tagihan Supplier';

    protected static ?string $title = 'Tagihan Supplier';

    protected static ?string $slug = 'tagihan-supplier';

    public const TAB_SUMMARY = 'ringkasan';

    public static function canAccess(): bool
    {
        return ApplicationAccess::allows('kelola keuangan');
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            self::TAB_SUMMARY => Tab::make('Ringkasan')->icon(Heroicon::OutlinedScale),
            SupplierBillLine::KIND_PURCHASE => Tab::make(SupplierBillLine::KINDS[SupplierBillLine::KIND_PURCHASE])->icon(Heroicon::OutlinedShoppingCart),
            SupplierBillLine::KIND_GOODS_OUT => Tab::make(SupplierBillLine::KINDS[SupplierBillLine::KIND_GOODS_OUT])->icon(Heroicon::OutlinedTruck),
        ];
    }

    public function getSubheading(): ?string
    {
        $unallocated = app(SupplierStatement::class)->unallocated();
        $start = Carbon::parse(app(SupplierBilling::class)->startDate())->format('d/m/Y');

        return "Beli jadi mulai {$start}: Barang Masuk ber-supplier = Tagihan Pembelian (stok masuk; penjualannya tidak ditagih lagi). "
            .'Penjualan saat stok konveksi tidak cukup = dikirim langsung supplier = Tagihan Barang Keluar (harga beli pada tanggal pesanan). '
            .'Retur masuk stok konveksi, tagihan tidak berubah.'
            .($unallocated['qty'] > 0 ? " {$unallocated['qty']} pcs ({$unallocated['variants']} SKU) belum ada supplier — isi Supplier di Model Produk, tagihan otomatis pindah." : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getTabsContentComponent(), EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $period = fn (): array => [
            'from' => $this->tableFilters['period']['from'] ?? null,
            'until' => $this->tableFilters['period']['until'] ?? null,
        ];
        $money = fn (TextColumn $column): TextColumn => $column->money('IDR', decimalPlaces: 0)->alignEnd();
        $tab = fn (string ...$tabs): bool => in_array($this->activeTab ?? self::TAB_SUMMARY, $tabs, true);

        return $table
            ->query(Supplier::query())
            // Kolom ringkasan selalu dihitung untuk periode filter (kosong = semua waktu).
            ->modifyQueryUsing(fn (Builder $query): Builder => app(SupplierStatement::class)->withTotals($query, $period()['from'], $period()['until']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                // Ringkasan
                $money(TextColumn::make('opening_balance')->label('Saldo awal'))->visible(fn (): bool => $tab(self::TAB_SUMMARY)),
                $money(TextColumn::make('billed_purchase')->label('Pembelian'))->visible(fn (): bool => $tab(self::TAB_SUMMARY)),
                $money(TextColumn::make('billed_goods_out')->label('Barang keluar'))->visible(fn (): bool => $tab(self::TAB_SUMMARY)),
                // Tagihan Pembelian
                TextColumn::make('qty_purchased')->label('Pcs dibeli')->numeric()->alignEnd()->visible(fn (): bool => $tab(SupplierBillLine::KIND_PURCHASE)),
                $money(TextColumn::make('billed_purchase_total')->label('Nilai pembelian')->state(fn (Supplier $record): float => (float) $record->billed_purchase))
                    ->weight('bold')
                    ->visible(fn (): bool => $tab(SupplierBillLine::KIND_PURCHASE)),
                // Tagihan Barang Keluar
                TextColumn::make('qty_sold')->label('Pcs dikirim supplier')->numeric()->alignEnd()->visible(fn (): bool => $tab(SupplierBillLine::KIND_GOODS_OUT)),
                $money(TextColumn::make('billed_goods_out_total')->label('Nilai barang keluar')->state(fn (Supplier $record): float => (float) $record->billed_goods_out))
                    ->weight('bold')
                    ->visible(fn (): bool => $tab(SupplierBillLine::KIND_GOODS_OUT)),
                $money(TextColumn::make('billed_consignment')->label('Titipan grosir'))->visible(fn (): bool => $tab(self::TAB_SUMMARY, SupplierBillLine::KIND_GOODS_OUT)),
                // Ringkasan
                $money(TextColumn::make('paid')->label('Dibayar'))->visible(fn (): bool => $tab(self::TAB_SUMMARY)),
                $money(TextColumn::make('closing_balance')
                    ->label('Sisa tagihan')
                    ->state(fn (Supplier $record): float => (float) $record->opening_balance + (float) $record->billed_normal + (float) $record->billed_consignment - (float) $record->paid)
                    ->weight('bold')
                    ->color(fn ($state): ?string => $state > 0 ? 'danger' : ($state < 0 ? 'success' : null)))
                    ->visible(fn (): bool => $tab(self::TAB_SUMMARY)),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->default(today()->startOfMonth()->toDateString()),
                        DatePicker::make('until')->label('Sampai')->default(today()->toDateString()),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (array $data): ?string => 'Periode: '.($data['from'] ?? 'awal').' s/d '.($data['until'] ?? 'hari ini')),
                TernaryFilter::make('active')
                    ->label('Supplier')
                    ->placeholder('Semua supplier')
                    ->trueLabel('Ada tagihan / pembayaran')
                    ->falseLabel('Tanpa transaksi')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereHas('billLines')->orWhereHas('payments')),
                        false: fn (Builder $query) => $query->whereDoesntHave('billLines')->whereDoesntHave('payments'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->deferFilters(false)
            ->recordActions([
                Action::make('detail')
                    ->label('Rincian')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (Supplier $record): string => SupplierBillLineResource::getUrl('index', ['filters' => array_filter([
                        'supplier_id' => ['value' => $record->id],
                        'kind' => $tab(self::TAB_SUMMARY) ? null : ['value' => $this->activeTab],
                        'period' => array_filter($period()),
                    ])])),
                Action::make('pay')
                    ->label('Bayar')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->fillForm(fn (Supplier $record): array => ['amount' => max(0, app(SupplierStatement::class)->balance($record)) ?: null, 'paid_at' => today()])
                    ->schema([
                        DatePicker::make('paid_at')->label('Tanggal bayar')->required(),
                        TextInput::make('amount')->label('Jumlah')->numeric()->minValue(1)->prefix('Rp')->required()
                            ->helperText('Terisi otomatis = sisa tagihan saat ini (pembelian + barang keluar).'),
                        Select::make('cash_account_id')->label('Dari rekening / kas')->options(fn (): array => CashAccount::query()->where('is_active', true)->pluck('name', 'id')->all()),
                        TextInput::make('note')->label('Catatan')->maxLength(255),
                    ])
                    ->action(function (Supplier $record, array $data): void {
                        SupplierPayment::create([...$data, 'supplier_id' => $record->id, 'created_by' => auth()->id()]);
                        Notification::make()->title('Pembayaran ke '.$record->name.' dicatat')->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            Action::make('rebuild')
                ->label('Hitung ulang tagihan')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => ApplicationAccess::allows('administrator'))
                ->requiresConfirmation()
                ->modalDescription('Alokasi stok vs dikirim langsung supplier setiap penjualan beli jadi diulang urut tanggal (kartu stok dikoreksi bila perlu), lalu Tagihan Pembelian & Tagihan Barang Keluar dibuat ulang. Pembayaran tidak berubah.')
                ->schema([
                    DatePicker::make('from')->label('Tagihkan pembelian & pesanan mulai')->default(fn (): string => app(SupplierBilling::class)->startDate())->required(),
                ])
                ->action(function (array $data, SupplierBilling $billing): void {
                    $result = $billing->rebuild($data['from']);
                    Notification::make()
                        ->title('Tagihan supplier dihitung ulang')
                        ->body("{$result['purchase']} baris pembelian, {$result['goods_out']} baris barang keluar, {$result['corrected']} penjualan dikoreksi alokasi stoknya. "
                            ."{$result['no_supplier']} pcs belum ada supplier (isi di Model Produk), {$result['no_price']} pcs belum ada harga beli.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
