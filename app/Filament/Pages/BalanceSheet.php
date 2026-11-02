<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Pages\Reports\FinancialReportPage;
use App\Models\Channel;
use App\Models\Reseller;
use App\Models\Store;
use App\Services\BalanceSheetService;
use App\Services\FinancialStatements;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * NERACA KEUANGAN: posisi aset, kewajiban & ekuitas konveksi dan tiap toko per akhir bulan.
 * Atas: rincian 1 sumber terpilih. Bawah: ringkasan semua sumber (tabel dengan cari, filter & paginasi).
 */
class BalanceSheet extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Neraca Keuangan';

    protected static ?string $title = 'Neraca Keuangan';

    protected static ?string $slug = 'neraca';

    public const KIND_KONVEKSI = 'konveksi';

    public const KIND_COMBINED = 'gabungan';

    public const KIND_STORE = 'toko';

    private const KINDS = [
        self::KIND_KONVEKSI => 'Konveksi',
        self::KIND_COMBINED => 'Gabungan toko',
        self::KIND_STORE => 'Toko',
    ];

    public string $source = BalanceSheetService::KONVEKSI;

    public string $period = '';

    /** @var array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>|null */
    private ?array $sheets = null;

    public function mount(): void
    {
        $this->period = today()->format('Y-m');
    }

    public function updatedPeriod(): void
    {
        $this->sheets = null;
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        $report = fn (): array => [
            'title' => 'Neraca',
            'entity' => $this->sheet()['label'],
            'columns' => ['Per '.$this->asOf()->locale('id')->translatedFormat('d M Y')],
            'rows' => FinancialReportPage::merge([FinancialStatements::balanceRows($this->sheet())]),
            'notes' => ['Disusun dari saldo buku besar (jurnal double-entry).'],
        ];

        return [
            ActionGroup::make([
                Action::make('print')
                    ->label('Cetak / PDF (sumber terpilih)')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->action(fn () => ResourceIo::openPrint($this, view('print.financial-report', [
                        'reports' => [$report()],
                        'documentTitle' => 'Neraca',
                        'printedBy' => auth()->user()?->name,
                    ])->render())),
                Action::make('excel')
                    ->label('Excel (.xlsx)')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->action(fn (): BinaryFileResponse => response()
                        ->download(FinancialReportPage::excel([$report()]), 'neraca-'.$this->asOf()->format('Ymd').'.xlsx')
                        ->deleteFileAfterSend()),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Posisi per '.$this->asOf()->locale('id')->translatedFormat('d F Y').'. Pilih sumber di atas atau klik baris pada ringkasan untuk melihat rinciannya.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('period')
                            ->label('Per akhir bulan')
                            ->options(fn (): array => $this->periodOptions())
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('source')
                            ->label('Rincian sumber')
                            ->options(fn (): array => array_map(fn (array $sheet): string => $sheet['label'], $this->sheets()))
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->live()
                            ->columnSpan(['md' => 2]),
                    ]),
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema([
                        Section::make('Aset')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->compact()
                            ->schema($this->sectionEntries('assets', 'Total Aset')),
                        Section::make('Kewajiban & Ekuitas')
                            ->icon(Heroicon::OutlinedBuildingLibrary)
                            ->compact()
                            ->schema([
                                ...$this->sectionEntries('liabilities', 'Total Kewajiban'),
                                ...$this->sectionEntries('equity', 'Total Ekuitas'),
                                TextEntry::make('liabilities_and_equity')
                                    ->label('Total Kewajiban + Ekuitas')
                                    ->state(fn (): float => BalanceSheetService::total($this->sheet()['liabilities'])
                                        + BalanceSheetService::total($this->sheet()['equity']))
                                    ->money('IDR', decimalPlaces: 0)
                                    ->weight(FontWeight::Bold)
                                    ->inlineLabel(),
                            ]),
                    ]),
                Section::make('Ringkasan Semua Sumber')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->compact()
                    ->schema([EmbeddedTable::make()]),
                Section::make('Catatan Perhitungan')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->compact()
                    ->collapsible()
                    ->collapsed()
                    ->persistCollapsed()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->state([
                                'Semua angka = saldo akun di buku besar (menu Akuntansi), dari jurnal otomatis tiap transaksi + jurnal manual. Aset selalu = kewajiban + ekuitas.',
                                'Kas per rekening. Saldo awal kas, aset tetap & pinjaman dicatat lewat Jurnal Umum (manual).',
                                'Persediaan barang jadi = barang masuk (harga beli / biaya produksi) - HPP terjual. Minus berarti stok awal belum dicatat: lakukan opname awal (centang "Opname awal") agar selisihnya masuk Saldo Awal.',
                                'Piutang reseller yang bersaldo kredit ditampilkan sebagai Deposit / Titipan Reseller. "Dibayar Reseller - Belum Dirinci" = potongan biaya reseller yang belum dihubungkan ke biaya / pembelian bahan / upah.',
                                'Toko: pembayaran reseller dibagi ke toko-tokonya sebanding tagihan s.d. tanggal bayar. Kas toko = omzet - fee admin - iklan - pembayaran ke konveksi.',
                            ])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->color('gray'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        $money = fn (string $name, string $label): TextColumn => TextColumn::make($name)
            ->label($label)
            ->money('IDR', decimalPlaces: 0)
            ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
            ->alignEnd()
            ->sortable();

        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search, ?array $filters): LengthAwarePaginator => $this->paginate(
                $this->summaryRows(), $page, $recordsPerPage, $sortColumn, $sortDirection, $search, $filters ?? [],
            ))
            ->columns([
                TextColumn::make('label')
                    ->label('Sumber')
                    ->weight(fn (array $record): ?string => $record['__key'] === $this->source ? 'bold' : null)
                    ->color(fn (array $record): ?string => $record['__key'] === $this->source ? 'primary' : null)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::KINDS[$state])
                    ->color(fn (string $state): string => match ($state) {
                        self::KIND_KONVEKSI => 'primary',
                        self::KIND_COMBINED => 'info',
                        default => 'gray',
                    }),
                $money('assets', 'Total Aset'),
                $money('liabilities', 'Kewajiban'),
                $money('equity', 'Ekuitas'),
                $money('ytd_profit', 'Laba Tahun Berjalan'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Jenis')
                    ->options(self::KINDS),
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->options(fn (): array => Reseller::withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('channel')
                    ->label('Marketplace / Channel')
                    ->options(fn (): array => Channel::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('negative')
                    ->label('Ekuitas')
                    ->placeholder('Semua')
                    ->trueLabel('Negatif (perlu dicek)')
                    ->falseLabel('Positif / nol'),
            ])
            ->deferFilters(false)
            ->recordAction('show')
            ->recordActions([
                Action::make('show')
                    ->label('Rincian')
                    ->icon(Heroicon::OutlinedEye)
                    ->action(function (array $record): void {
                        $this->source = $record['__key'];
                        $this->js("window.scrollTo({ top: 0, behavior: 'smooth' })");
                    }),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->emptyStateHeading('Tidak ada sumber yang cocok')
            ->emptyStateIcon(Heroicon::OutlinedScale);
    }

    /**
     * @return list<RepeatableEntry|TextEntry>
     */
    private function sectionEntries(string $section, string $totalLabel): array
    {
        return [
            RepeatableEntry::make($section)
                ->hiddenLabel()
                ->state(fn (): array => $this->sheet()[$section])
                ->table([
                    TableColumn::make(match ($section) {
                        'liabilities' => 'Kewajiban',
                        'equity' => 'Ekuitas',
                        default => 'Pos',
                    }),
                    TableColumn::make('Jumlah')->alignEnd(),
                ])
                ->schema([
                    TextEntry::make('label'),
                    TextEntry::make('amount')
                        ->money('IDR', decimalPlaces: 0)
                        ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                        ->alignEnd(),
                ]),
            TextEntry::make("{$section}_total")
                ->label($totalLabel)
                ->state(fn (): float => BalanceSheetService::total($this->sheet()[$section]))
                ->money('IDR', decimalPlaces: 0)
                ->weight(FontWeight::Bold)
                ->inlineLabel(),
        ];
    }

    /**
     * @return Collection<int, array{__key: string, label: string, kind: string, reseller_id: ?int, channel_id: ?int, assets: float, liabilities: float, equity: float, ytd_profit: float}>
     */
    private function summaryRows(): Collection
    {
        $stores = Store::withTrashed()->get(['id', 'reseller_id', 'channel_id'])->keyBy('id');

        return collect($this->sheets())->map(function (array $sheet, string $key) use ($stores): array {
            $store = str_starts_with($key, 'store:') ? $stores[(int) Str::after($key, 'store:')] ?? null : null;

            return [
                '__key' => $key,
                'label' => $sheet['label'],
                'kind' => match (true) {
                    $key === BalanceSheetService::KONVEKSI => self::KIND_KONVEKSI,
                    $key === BalanceSheetService::ALL_STORES => self::KIND_COMBINED,
                    default => self::KIND_STORE,
                },
                'reseller_id' => $store?->reseller_id,
                'channel_id' => $store?->channel_id,
                'assets' => BalanceSheetService::total($sheet['assets']),
                'liabilities' => BalanceSheetService::total($sheet['liabilities']),
                'equity' => BalanceSheetService::total($sheet['equity']),
                'ytd_profit' => $sheet['ytd_profit'],
            ];
        })->values();
    }

    /**
     * Cari, filter, urut & paginasi pada baris ringkasan (data kustom, bukan query).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    private function paginate(Collection $rows, int $page, int|string $perPage, ?string $sortColumn, ?string $sortDirection, ?string $search, array $filters): LengthAwarePaginator
    {
        $kind = $filters['kind']['value'] ?? null;
        $reseller = $filters['reseller']['value'] ?? null;
        $channel = $filters['channel']['value'] ?? null;
        $negative = $filters['negative']['value'] ?? null;

        $rows = $rows
            ->when(filled($search), fn (Collection $rows) => $rows->filter(fn (array $row): bool => Str::contains($row['label'], $search, ignoreCase: true)))
            ->when(filled($kind), fn (Collection $rows) => $rows->where('kind', $kind))
            ->when(filled($reseller), fn (Collection $rows) => $rows->where('reseller_id', (int) $reseller))
            ->when(filled($channel), fn (Collection $rows) => $rows->where('channel_id', (int) $channel))
            ->when(filled($negative), fn (Collection $rows) => $rows->filter(fn (array $row): bool => ($row['equity'] < 0) === (bool) $negative))
            ->when($sortColumn, fn (Collection $rows) => $rows->sortBy(fn (array $row) => $row[$sortColumn] ?? null, SORT_NATURAL, $sortDirection === 'desc'))
            ->values();

        $perPage = $perPage === 'all' ? max(1, $rows->count()) : (int) $perPage;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);
    }

    /**
     * @return array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}
     */
    private function sheet(): array
    {
        $sheets = $this->sheets();

        return $sheets[$this->source] ?? $sheets[BalanceSheetService::KONVEKSI];
    }

    /**
     * @return array<string, array{label: string, assets: list<array{label: string, amount: float}>, liabilities: list<array{label: string, amount: float}>, equity: list<array{label: string, amount: float}>, ytd_profit: float}>
     */
    private function sheets(): array
    {
        return $this->sheets ??= app(BalanceSheetService::class)->build($this->asOf());
    }

    private function asOf(): Carbon
    {
        $month = rescue(fn (): Carbon => Carbon::createFromFormat('!Y-m', $this->period), today()->startOfMonth(), report: false);

        return $month->endOfMonth()->min(today());
    }

    /**
     * @return array<string, string>
     */
    private function periodOptions(): array
    {
        $options = [];
        $month = today()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $options[$month->format('Y-m')] = $month->locale('id')->translatedFormat('F Y');
            $month = $month->copy()->subMonth();
        }

        return $options;
    }
}
