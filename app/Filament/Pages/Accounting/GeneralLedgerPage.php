<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\Account;
use App\Models\JournalLine;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\Tailor;
use App\Services\GeneralLedger;
use App\Support\ChartOfAccounts;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * BUKU BESAR per akun: saldo awal, mutasi debit/kredit dan saldo berjalan; bisa disaring per buku (konveksi / toko)
 * dan lawan transaksi (reseller, supplier, penjahit).
 */
class GeneralLedgerPage extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar';

    protected static ?string $slug = 'akuntansi/buku-besar';

    #[Url]
    public ?int $account = null;

    #[Url]
    public string $entity = GeneralLedger::KONVEKSI;

    public ?string $preset = 'this_month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public ?string $counterparty = null;

    public function mount(): void
    {
        app(GeneralLedger::class)->ensureFresh();

        $this->account ??= Account::query()->where('ledger', ChartOfAccounts::KONVEKSI)->whereNotNull('cash_account_id')->orderBy('code')->value('id')
            ?? Account::query()->orderBy('code')->value('id');

        if ($this->from === null || $this->until === null) {
            [$this->from, $this->until] = DateRangeFilter::range($this->preset);
        } else {
            $this->preset = DateRangeFilter::CUSTOM;
        }

        $this->alignEntity();
    }

    public function updatedAccount(): void
    {
        $this->alignEntity();
        $this->counterparty = null;
        $this->resetTable();
    }

    public function updatedPreset(?string $preset): void
    {
        if ($preset && $preset !== DateRangeFilter::CUSTOM) {
            [$this->from, $this->until] = DateRangeFilter::range($preset);
        }

        $this->resetTable();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'until'], true)) {
            $this->preset = DateRangeFilter::CUSTOM;
        }

        if (in_array($property, ['from', 'until', 'entity', 'counterparty'], true)) {
            $this->resetTable();
        }
    }

    /** Akun toko hanya ada di buku toko, akun konveksi di buku konveksi. */
    private function alignEntity(): void
    {
        $isStore = $this->accountModel()?->ledger === ChartOfAccounts::STORE;

        if ($isStore && $this->entity === GeneralLedger::KONVEKSI) {
            $this->entity = GeneralLedger::ALL_STORES;
        } elseif (! $isStore) {
            $this->entity = GeneralLedger::KONVEKSI;
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->compact()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 6])->schema([
                        Select::make('account')
                            ->label('Akun')
                            ->options(fn (): array => Account::query()->orderBy('ledger')->orderBy('code')->get()
                                ->groupBy(fn (Account $account): string => 'Buku '.ChartOfAccounts::LEDGERS[$account->ledger])
                                ->map(fn ($accounts) => $accounts->mapWithKeys(fn (Account $account): array => [$account->id => $account->label()])->all())
                                ->all())
                            ->searchable()
                            ->selectablePlaceholder(false)
                            ->live()
                            ->columnSpan(['lg' => 2]),
                        Select::make('entity')
                            ->label('Buku')
                            ->options(fn (): array => $this->accountModel()?->ledger === ChartOfAccounts::STORE
                                ? array_diff_key(GeneralLedger::entities(), [GeneralLedger::KONVEKSI => true])
                                : [GeneralLedger::KONVEKSI => 'Konveksi'])
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->live(),
                        Select::make('preset')
                            ->label('Periode')
                            ->options(DateRangeFilter::PRESETS)
                            ->selectablePlaceholder(false)
                            ->live(),
                        DatePicker::make('from')->label('Dari')->required()->live(),
                        DatePicker::make('until')->label('Sampai')->required()->live(),
                        Select::make('counterparty')
                            ->label('Lawan transaksi')
                            ->options(fn (): array => $this->counterpartyOptions())
                            ->placeholder('Semua')
                            ->searchable()
                            ->live()
                            ->visible(fn (): bool => $this->counterpartyOptions() !== [])
                            ->columnSpan(['lg' => 2]),
                    ]),
                ]),
            Grid::make(['default' => 2, 'lg' => 4])->schema([
                $this->stat('opening', 'Saldo awal', fn (): float => $this->opening()),
                $this->stat('debit', 'Mutasi debit', fn (): float => $this->totals()['debit']),
                $this->stat('credit', 'Mutasi kredit', fn (): float => $this->totals()['credit']),
                $this->stat('closing', 'Saldo akhir', fn (): float => $this->opening() + $this->sign() * ($this->totals()['debit'] - $this->totals()['credit'])),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    private function stat(string $name, string $label, callable $state): Section
    {
        return Section::make()->compact()->schema([
            TextEntry::make($name)
                ->label($label)
                ->state($state)
                ->formatStateUsing(fn ($state): string => Number::currency((float) $state, 'IDR', 'id', 0))
                ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                ->size('lg')
                ->weight('bold'),
        ]);
    }

    public function getSubheading(): ?string
    {
        $account = $this->accountModel();

        return $account ? $account->label().' · saldo normal '.($account->normal_balance === 'debit' ? 'debit' : 'kredit').' (saldo positif = sesuai saldo normal).' : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->linesQuery()
                ->select('journal_lines.*')
                ->selectRaw('? + ? * SUM(journal_lines.debit - journal_lines.credit) OVER (ORDER BY journal_lines.entry_date, journal_lines.entry_id, journal_lines.id) AS running', [$this->opening(), $this->sign()])
                ->with(['entry', 'reseller:id,name', 'supplier:id,name', 'tailor:id,name'])
                ->orderBy('journal_lines.entry_date')->orderBy('journal_lines.entry_id')->orderBy('journal_lines.id'))
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d/m/Y'),
                TextColumn::make('entry.number')
                    ->label('No. / Sumber')
                    ->state(fn (JournalLine $record): string => $record->entry->number ?? $record->entry->sourceLabel())
                    ->badge()
                    ->color(fn (JournalLine $record): string => $record->entry->is_auto ? 'gray' : 'primary'),
                TextColumn::make('entry.description')
                    ->label('Keterangan')
                    ->description(fn (JournalLine $record): ?string => $record->memo)
                    ->wrap(),
                TextColumn::make('counterparty')
                    ->label('Lawan')
                    ->state(fn (JournalLine $record): ?string => $record->reseller?->name ?? $record->supplier?->name ?? $record->tailor?->name)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('debit')
                    ->label('Debit')
                    ->formatStateUsing(fn ($state): string => (float) $state ? Number::currency((float) $state, 'IDR', 'id', 0) : '-')
                    ->alignEnd(),
                TextColumn::make('credit')
                    ->label('Kredit')
                    ->formatStateUsing(fn ($state): string => (float) $state ? Number::currency((float) $state, 'IDR', 'id', 0) : '-')
                    ->alignEnd(),
                TextColumn::make('running')
                    ->label('Saldo')
                    ->formatStateUsing(fn ($state): string => Number::currency((float) $state, 'IDR', 'id', 0))
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->recordUrl(fn (JournalLine $record): string => JournalEntryResource::getUrl('view', ['record' => $record->entry_id]))
            ->paginated([25, 50, 100, 250])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Tidak ada mutasi pada periode ini')
            ->emptyStateIcon(Heroicon::OutlinedBookOpen);
    }

    /**
     * Baris akun terpilih pada buku & lawan transaksi terpilih (tanpa batas tanggal).
     */
    private function baseQuery(): Builder
    {
        $query = JournalLine::query()->where('journal_lines.account_id', $this->account ?? 0);
        app(GeneralLedger::class)->scope($query, $this->entity, 'journal_lines');

        if (filled($this->counterparty) && str_contains($this->counterparty, ':')) {
            [$column, $id] = explode(':', $this->counterparty, 2);
            $query->where('journal_lines.'.$column, (int) $id);
        }

        return $query;
    }

    private function linesQuery(): Builder
    {
        return $this->baseQuery()
            ->whereBetween('journal_lines.entry_date', [$this->range()[0]->toDateString(), $this->range()[1]->toDateString()]);
    }

    /** Saldo awal (sesuai saldo normal) sebelum tanggal "dari". */
    public function opening(): float
    {
        return round($this->sign() * (float) $this->baseQuery()
            ->where('journal_lines.entry_date', '<', $this->range()[0]->toDateString())
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    /**
     * @return array{debit: float, credit: float}
     */
    public function totals(): array
    {
        $row = $this->linesQuery()->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debit, COALESCE(SUM(journal_lines.credit), 0) AS credit')->toBase()->first();

        return ['debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2)];
    }

    private function sign(): int
    {
        return $this->accountModel()?->normal_balance === 'credit' ? -1 : 1;
    }

    private function accountModel(): ?Account
    {
        return $this->account ? Account::query()->find($this->account) : null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(): array
    {
        $until = Carbon::parse($this->until ?: today());

        return [Carbon::parse($this->from ?: $until->copy()->startOfMonth())->min($until), $until];
    }

    /**
     * Lawan transaksi yang pernah tercatat di akun ini.
     *
     * @return array<string, array<string, string>>
     */
    private function counterpartyOptions(): array
    {
        $options = [];

        foreach (['reseller_id' => [Reseller::class, 'Reseller'], 'supplier_id' => [Supplier::class, 'Supplier'], 'tailor_id' => [Tailor::class, 'Penjahit / tukang potong']] as $column => [$model, $group]) {
            $ids = JournalLine::query()->where('account_id', $this->account ?? 0)->whereNotNull($column)->distinct()->pluck($column);

            if ($ids->isNotEmpty()) {
                $options[$group] = $model::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')
                    ->mapWithKeys(fn (string $name, int $id): array => ["{$column}:{$id}" => $name])->all();
            }
        }

        return $options;
    }
}
