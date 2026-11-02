<?php

namespace App\Filament\Resources\ExpenseCategories;

use App\Filament\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Support\ChartOfAccounts;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Kategori Biaya';

    protected static ?string $pluralModelLabel = 'Kategori Biaya';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->helperText('LISTRIK_AIR, SEWA, GAJI_STAF, GAJI_ADMIN_TOKO, PACKING, ONGKIR, ...')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(80),
                Select::make('account_id')
                    ->label('Akun Buku Besar')
                    ->options(fn (): array => Account::query()->where('ledger', ChartOfAccounts::KONVEKSI)
                        ->whereIn('type', ['cogs', 'expense', 'other_expense', 'asset'])->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => $account->label()])->all())
                    ->searchable()
                    ->placeholder('Buat akun beban baru otomatis')
                    ->helperText('Gaji produksi -> Biaya Overhead Produksi (masuk HPP); gaji admin/toko -> Beban Gaji Karyawan. Kosong = dibuatkan akun "Beban <nama>".'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('account')->withSum('expenses', 'amount'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('account.code')
                    ->label('Akun')
                    ->state(fn (ExpenseCategory $record): ?string => $record->account?->label())
                    ->placeholder('-'),
                TextColumn::make('expenses_sum_amount')
                    ->label('Total Biaya')
                    ->money('IDR', decimalPlaces: 0),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseCategories::route('/'),
        ];
    }
}
