<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Pages\Accounting\GeneralLedgerPage;
use App\Filament\Resources\Accounts\Pages\ManageAccounts;
use App\Models\Account;
use App\Support\ChartOfAccounts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * BAGAN AKUN konveksi & toko. Akun sistem (ber-role) dipakai jurnal otomatis: boleh diganti kode/nama, tidak boleh dihapus.
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Bagan Akun';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Bagan Akun';

    protected static ?string $slug = 'akuntansi/bagan-akun';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('ledger')
                    ->label('Buku')
                    ->options(ChartOfAccounts::LEDGERS)
                    ->default(ChartOfAccounts::KONVEKSI)
                    ->selectablePlaceholder(false)
                    ->disabled(fn (?Account $record): bool => $record?->role !== null || $record?->cash_account_id !== null)
                    ->required(),
                TextInput::make('code')
                    ->label('Kode')
                    ->placeholder('mis. 6-1200')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('ledger', $get('ledger') ?? ChartOfAccounts::KONVEKSI)),
                TextInput::make('name')
                    ->label('Nama Akun')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),
                Select::make('type')
                    ->label('Jenis')
                    ->options(ChartOfAccounts::TYPES)
                    ->required()
                    ->live()
                    ->disabled(fn (?Account $record): bool => $record?->role !== null || $record?->cash_account_id !== null)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $state ? $set('normal_balance', ChartOfAccounts::normalBalance($state)) : null),
                Select::make('normal_balance')
                    ->label('Saldo Normal')
                    ->options(['debit' => 'Debit', 'credit' => 'Kredit'])
                    ->helperText('Akun lawan (mis. akumulasi penyusutan, prive) bersaldo normal kebalikan jenisnya.')
                    ->required(),
                Select::make('cash_flow')
                    ->label('Kelompok Arus Kas')
                    ->options(ChartOfAccounts::CASH_FLOWS)
                    ->placeholder('Operasi')
                    ->helperText('Bila kas dibayar / diterima untuk akun ini, masuk kelompok arus kas mana.'),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->inline(false),
                TextInput::make('description')
                    ->label('Keterangan')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cashAccount'))
            ->defaultSort('code')
            ->defaultGroup('ledger')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->description(fn (Account $record): ?string => $record->description),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ChartOfAccounts::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'asset' => 'info', 'liability' => 'warning', 'equity' => 'primary', 'revenue', 'other_income' => 'success', default => 'danger',
                    }),
                TextColumn::make('normal_balance')
                    ->label('Saldo Normal')
                    ->formatStateUsing(fn (string $state): string => $state === 'debit' ? 'Debit' : 'Kredit')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')
                    ->label('Dipakai Otomatis')
                    ->formatStateUsing(fn (?string $state, Account $record): string => $record->cash_account_id ? 'Rekening '.$record->cashAccount?->name : ($state ? 'Ya' : '-'))
                    ->placeholder('-')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('cash_flow')
                    ->label('Arus Kas')
                    ->formatStateUsing(fn (?string $state): string => ChartOfAccounts::CASH_FLOWS[$state] ?? '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('ledger')
                    ->label('Buku')
                    ->options(ChartOfAccounts::LEDGERS),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(ChartOfAccounts::TYPES)
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
            ])
            ->recordActions([
                Action::make('ledger')
                    ->label('Buku Besar')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->color('gray')
                    ->url(fn (Account $record): string => GeneralLedgerPage::getUrl(['account' => $record->id])),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Account $record): bool => $record->role !== null || $record->cash_account_id !== null),
            ])
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(100);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
