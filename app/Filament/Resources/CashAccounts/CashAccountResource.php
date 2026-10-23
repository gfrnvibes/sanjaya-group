<?php

namespace App\Filament\Resources\CashAccounts;

use App\Filament\Resources\CashAccounts\Pages\ManageCashAccounts;
use App\Models\CashAccount;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CashAccountResource extends Resource
{
    protected static ?string $model = CashAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Rekening / Kas';

    protected static ?string $pluralModelLabel = 'Rekening / Kas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(80)
                    ->unique(ignoreRecord: true),
                TextInput::make('bank')
                    ->label('Bank')
                    ->maxLength(60),
                TextInput::make('holder')
                    ->label('Atas Nama')
                    ->maxLength(120),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withSum('resellerPayments as payments_in', 'amount')
                ->withSum('expenses as expenses_out', 'amount')
                ->withSum('tailorPayments as tailor_out', 'amount')
                ->withSum('supplierPayments as supplier_out', 'amount'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('bank')
                    ->label('Bank')
                    ->placeholder('-'),
                TextColumn::make('holder')
                    ->label('Atas Nama')
                    ->placeholder('-'),
                TextColumn::make('payments_in')
                    ->label('Masuk dari Reseller')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('expenses_out')
                    ->label('Keluar Biaya')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('tailor_out')
                    ->label('Keluar Upah Jahit')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('supplier_out')
                    ->label('Keluar ke Supplier')
                    ->money('IDR', decimalPlaces: 0),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
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
            'index' => ManageCashAccounts::route('/'),
        ];
    }
}
