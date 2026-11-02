<?php

namespace App\Filament\Resources\JournalEntries;

use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Reseller;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\ChartOfAccounts;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * JURNAL UMUM: semua jurnal (otomatis dari transaksi & manual). Jurnal manual untuk saldo awal, penyusutan,
 * pinjaman, pembayaran pajak, koreksi; jurnal otomatis hanya bisa dilihat.
 */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Jurnal Umum';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?string $slug = 'akuntansi/jurnal';

    public static function canEdit(Model $record): bool
    {
        return ! $record->is_auto && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->is_auto && parent::canDelete($record);
    }

    public static function form(Schema $schema): Schema
    {
        $money = fn (string $name): TextInput => TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->prefix('Rp')
            ->live(onBlur: true);

        return $schema->components([
            Section::make('Jurnal')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('entry_date')
                        ->label('Tanggal')
                        ->default(today())
                        ->required(),
                    Select::make('store_id')
                        ->label('Buku')
                        ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->map(fn (string $name): string => 'Toko: '.$name)->all())
                        ->placeholder('Konveksi')
                        ->helperText('Kosong = buku konveksi.')
                        ->searchable()
                        ->live(),
                    TextInput::make('number')
                        ->label('No. Jurnal')
                        ->placeholder('Otomatis (JU-yymm-0001)')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('description')
                        ->label('Keterangan')
                        ->placeholder('mis. Saldo awal kas per 1 Agustus 2026')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
            Section::make('Baris Jurnal')
                ->description(fn (Get $get): string => self::balanceText($get('lines') ?? []))
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->relationship()
                        ->table([
                            TableColumn::make('Akun')->width('30%'),
                            TableColumn::make('Debit'),
                            TableColumn::make('Kredit'),
                            TableColumn::make('Reseller'),
                            TableColumn::make('Supplier'),
                            TableColumn::make('Memo'),
                        ])
                        ->schema([
                            Select::make('account_id')
                                ->options(fn (Get $get): array => Account::query()
                                    ->where('ledger', $get('../../store_id') ? ChartOfAccounts::STORE : ChartOfAccounts::KONVEKSI)
                                    ->where('is_active', true)->orderBy('code')->get()
                                    ->mapWithKeys(fn (Account $account): array => [$account->id => $account->label()])->all())
                                ->searchable()
                                ->required(),
                            $money('debit'),
                            $money('credit'),
                            Select::make('reseller_id')
                                ->options(fn (): array => Reseller::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->placeholder('-')
                                ->searchable(),
                            Select::make('supplier_id')
                                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->placeholder('-')
                                ->searchable(),
                            TextInput::make('memo')
                                ->maxLength(255),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::lineData($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::lineData($data))
                        ->minItems(2)
                        ->defaultItems(2)
                        ->addActionLabel('Tambah baris')
                        ->live(),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function lineData(array $data): array
    {
        return [...$data, 'debit' => (float) ($data['debit'] ?? 0), 'credit' => (float) ($data['credit'] ?? 0)];
    }

    /**
     * Validasi jurnal manual: tiap baris debit ATAU kredit, total debit = total kredit.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    public static function validateLines(array $lines): void
    {
        $debit = $credit = 0.0;

        foreach (array_values($lines) as $index => $line) {
            [$d, $c] = [(float) ($line['debit'] ?? 0), (float) ($line['credit'] ?? 0)];

            if (($d > 0) === ($c > 0)) {
                throw ValidationException::withMessages(['data.lines' => 'Baris '.($index + 1).': isi debit ATAU kredit (salah satu, lebih dari 0).']);
            }

            [$debit, $credit] = [$debit + $d, $credit + $c];
        }

        if (count($lines) < 2 || round($debit, 2) !== round($credit, 2)) {
            throw ValidationException::withMessages(['data.lines' => 'Jurnal tidak seimbang: total debit '.Number::currency($debit, 'IDR', 'id', 0).' ≠ total kredit '.Number::currency($credit, 'IDR', 'id', 0).'.']);
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    private static function balanceText(array $lines): string
    {
        $debit = array_sum(array_map(fn (array $line): float => (float) ($line['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn (array $line): float => (float) ($line['credit'] ?? 0), $lines));
        $rupiah = fn (float $value): string => Number::currency($value, 'IDR', 'id', 0);

        return 'Total debit '.$rupiah($debit).' · total kredit '.$rupiah($credit).(round($debit, 2) === round($credit, 2) ? ' · seimbang ✓' : ' · selisih '.$rupiah(abs($debit - $credit)));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('entry_date')->label('Tanggal')->date('d/m/Y'),
                    TextEntry::make('number')->label('No. / Sumber')
                        ->state(fn (JournalEntry $record): string => $record->number ?? $record->sourceLabel().($record->source_key ? ' ('.$record->source_key.')' : '')),
                    TextEntry::make('store.name')->label('Buku')->placeholder('Konveksi'),
                    TextEntry::make('is_auto')->label('Jenis')->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Otomatis' : 'Manual')
                        ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                    TextEntry::make('description')->label('Keterangan')->columnSpanFull(),
                ]),
            Section::make('Baris Jurnal')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->table([
                            InfolistColumn::make('Akun'),
                            InfolistColumn::make('Lawan'),
                            InfolistColumn::make('Debit')->alignEnd(),
                            InfolistColumn::make('Kredit')->alignEnd(),
                            InfolistColumn::make('Memo'),
                        ])
                        ->schema([
                            TextEntry::make('account.name')->state(fn (JournalLine $record): string => $record->account->label()),
                            TextEntry::make('counterparty')->state(fn (JournalLine $record): ?string => $record->reseller?->name ?? $record->supplier?->name ?? $record->tailor?->name)->placeholder('-'),
                            TextEntry::make('debit')->money('IDR', decimalPlaces: 0)->alignEnd()->formatStateUsing(fn ($state): string => (float) $state ? Number::currency((float) $state, 'IDR', 'id', 0) : '-'),
                            TextEntry::make('credit')->money('IDR', decimalPlaces: 0)->alignEnd()->formatStateUsing(fn ($state): string => (float) $state ? Number::currency((float) $state, 'IDR', 'id', 0) : '-'),
                            TextEntry::make('memo')->placeholder('-'),
                        ]),
                    TextEntry::make('total')
                        ->label('Total')
                        ->state(fn (JournalEntry $record): string => Number::currency((float) $record->lines->sum('debit'), 'IDR', 'id', 0).' (debit = kredit)')
                        ->inlineLabel(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('store:id,name')->withSum('lines', 'debit'))
            ->defaultSort('entry_date', 'desc')
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('No. / Sumber')
                    ->state(fn (JournalEntry $record): string => $record->number ?? $record->sourceLabel())
                    ->badge()
                    ->color(fn (JournalEntry $record): string => $record->is_auto ? 'gray' : 'primary'),
                TextColumn::make('description')
                    ->label('Keterangan')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('store.name')
                    ->label('Buku')
                    ->placeholder('Konveksi')
                    ->toggleable(),
                TextColumn::make('lines_sum_debit')
                    ->label('Nilai')
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd(),
                IconColumn::make('is_auto')
                    ->label('Otomatis')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                DateRangeFilter::make('period', 'Periode', fn (Builder $query, ?string $from, ?string $until): Builder => $query
                    ->when($from, fn (Builder $query) => $query->where('entry_date', '>=', $from))
                    ->when($until, fn (Builder $query) => $query->where('entry_date', '<=', $until))),
                SelectFilter::make('book')
                    ->label('Buku')
                    ->options(fn (): array => ['konveksi' => 'Konveksi', 'stores' => 'Semua toko'] + Store::query()->orderBy('name')->pluck('name', 'id')->map(fn (string $name): string => 'Toko: '.$name)->all())
                    ->default('konveksi')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'konveksi' => $query->whereNull('store_id'),
                        'stores' => $query->whereNotNull('store_id'),
                        default => $query->where('store_id', (int) $data['value']),
                    }),
                SelectFilter::make('source_type')
                    ->label('Sumber')
                    ->options(JournalEntry::SOURCES)
                    ->multiple(),
                SelectFilter::make('account')
                    ->label('Akun')
                    ->options(fn (): array => Account::query()->orderBy('ledger')->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => $account->label().($account->ledger === ChartOfAccounts::STORE ? ' (toko)' : '')])->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lines', fn (Builder $query) => $query->where('account_id', $data['value']))
                        : $query),
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->options(fn (): array => Reseller::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lines', fn (Builder $query) => $query->where('reseller_id', $data['value']))
                        : $query),
                TernaryFilter::make('is_auto')
                    ->label('Jenis')
                    ->trueLabel('Otomatis')
                    ->falseLabel('Manual'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'view' => ViewJournalEntry::route('/{record}'),
            'edit' => EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
