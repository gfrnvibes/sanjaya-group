<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SupplierBillLines\SupplierBillLineResource;
use App\Models\CashAccount;
use App\Models\Supplier;
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
use Filament\Schemas\Components\EmbeddedTable;
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
 * TAGIHAN SUPPLIER per periode: saldo awal, pcs terjual, tagihan normal (FIFO lot), titipan grosir bordir,
 * pembayaran, dan sisa tagihan. Rincian per pesanan di halaman Rincian Tagihan Supplier.
 */
class SupplierBills extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Tagihan Supplier';

    protected static ?string $title = 'Tagihan Supplier';

    protected static ?string $slug = 'tagihan-supplier';

    public static function canAccess(): bool
    {
        return ApplicationAccess::allows('kelola keuangan');
    }

    public function getSubheading(): ?string
    {
        $unallocated = app(SupplierStatement::class)->unallocated();

        return 'Tagihan dihitung otomatis dari pesanan Selesai produk beli jadi (FIFO lot Barang Masuk) mulai '
            .Carbon::parse(app(SupplierBilling::class)->startDate())->format('d/m/Y').'. '
            .'Titipan grosir bordir diteruskan utuh ke supplier.'
            .($unallocated['qty'] > 0 ? " {$unallocated['qty']} pcs ({$unallocated['variants']} SKU) belum teralokasi karena stok minus — otomatis ditagihkan saat Barang Masuk berikutnya." : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $period = fn (): array => [
            'from' => $this->tableFilters['period']['from'] ?? null,
            'until' => $this->tableFilters['period']['until'] ?? null,
        ];
        $money = fn (TextColumn $column): TextColumn => $column->money('IDR', decimalPlaces: 0)->alignEnd();

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
                $money(TextColumn::make('opening_balance')->label('Saldo awal')->toggleable()),
                TextColumn::make('qty_sold')->label('Pcs')->numeric()->alignEnd(),
                $money(TextColumn::make('billed_normal')->label('Tagihan barang')),
                $money(TextColumn::make('billed_consignment')->label('Titipan grosir')->toggleable()),
                $money(TextColumn::make('paid')->label('Dibayar')),
                $money(TextColumn::make('closing_balance')
                    ->label('Sisa tagihan')
                    ->state(fn (Supplier $record): float => (float) $record->opening_balance + (float) $record->billed_normal + (float) $record->billed_consignment - (float) $record->paid)
                    ->weight('bold')
                    ->color(fn ($state): ?string => $state > 0 ? 'danger' : ($state < 0 ? 'success' : null))),
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
                    ->url(fn (Supplier $record): string => SupplierBillLineResource::getUrl('index', ['filters' => [
                        'supplier_id' => ['value' => $record->id],
                        'period' => array_filter($period()),
                    ]])),
                Action::make('pay')
                    ->label('Bayar')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->fillForm(fn (Supplier $record): array => ['amount' => max(0, app(SupplierStatement::class)->balance($record)) ?: null, 'paid_at' => today()])
                    ->schema([
                        DatePicker::make('paid_at')->label('Tanggal bayar')->required(),
                        TextInput::make('amount')->label('Jumlah')->numeric()->minValue(1)->prefix('Rp')->required()
                            ->helperText('Terisi otomatis = sisa tagihan saat ini.'),
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
                ->modalDescription('Semua lot & tagihan dihitung ulang dari Barang Masuk Beli Jadi dan pesanan Selesai (FIFO). Stok sebelum tanggal mulai ikut dipakai, tetapi hanya pesanan mulai tanggal ini yang ditagihkan. Pembayaran tidak berubah.')
                ->schema([
                    DatePicker::make('from')->label('Tagihkan pesanan mulai')->default(fn (): string => app(SupplierBilling::class)->startDate())->required(),
                ])
                ->action(function (array $data, SupplierBilling $billing): void {
                    $result = $billing->rebuild($data['from']);
                    Notification::make()
                        ->title('Tagihan supplier dihitung ulang')
                        ->body("{$result['lots']} lot, {$result['lines']} baris tagihan, {$result['unallocated']} pcs belum teralokasi.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
