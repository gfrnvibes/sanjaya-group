<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Forms\Components\ResellerOffsetSelect;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Expense;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Biaya';

    protected static ?string $pluralModelLabel = 'Biaya Operasional';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('expense_date')
                    ->label('Tanggal')
                    ->default(today())
                    ->required(),
                Select::make('category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->preload()
                    ->required()
                    // Agar laba tidak terpotong dua kali (upah & bahan sudah masuk HPP).
                    ->helperText('Bukan untuk upah penjahit/potong (catat di Pembayaran Penjahit) atau belanja bahan (catat di Pembelian Bahan): keduanya sudah masuk HPP.')
                    ->createOptionForm([
                        TextInput::make('code')->label('Kode')->required(),
                        TextInput::make('name')->label('Nama')->required(),
                    ]),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                Select::make('cash_account_id')
                    ->label('Dari Rekening / Kas')
                    ->relationship('cashAccount', 'name')
                    ->preload(),
                Select::make('store_id')
                    ->label('Untuk Toko')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('reseller_id')
                    ->label('Untuk Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload(),
                ResellerOffsetSelect::make()
                    ->label('Dibayar Reseller (Potongan Biaya)')
                    ->placeholder('Tidak (dibayar konveksi)'),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'store', 'reseller', 'cashAccount']))
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('expense_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('reseller.name')
                    ->label('Reseller')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('cashAccount.name')
                    ->label('Rekening')
                    ->placeholder('-'),
                TextColumn::make('reseller_payment_id')
                    ->label('Potongan Biaya')
                    ->formatStateUsing(fn ($state): string => $state ? 'Ya' : '-')
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->preload()
                    ->multiple(),
                SelectFilter::make('store')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->searchable(),
                Filter::make('expense_date')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('expense_date', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('expense_date', '<=', $date))),
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
            'index' => ManageExpenses::route('/'),
        ];
    }
}
