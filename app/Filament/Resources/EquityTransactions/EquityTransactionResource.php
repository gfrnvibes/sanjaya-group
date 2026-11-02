<?php

namespace App\Filament\Resources\EquityTransactions;

use App\Filament\Resources\EquityTransactions\Pages\ManageEquityTransactions;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\EquityTransaction;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * MODAL & PRIVE pemilik konveksi: masuk Neraca (ekuitas), Arus Kas (pendanaan) dan Laporan Perubahan Modal.
 */
class EquityTransactionResource extends Resource
{
    protected static ?string $model = EquityTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Modal & Prive';

    protected static ?string $modelLabel = 'Transaksi Modal';

    protected static ?string $pluralModelLabel = 'Modal & Prive';

    protected static ?string $slug = 'modal-prive';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ToggleButtons::make('type')
                    ->label('Jenis')
                    ->options(EquityTransaction::TYPES)
                    ->colors([EquityTransaction::DEPOSIT => 'success', EquityTransaction::DRAWING => 'warning'])
                    ->default(EquityTransaction::DEPOSIT)
                    ->inline()
                    ->required()
                    ->columnSpanFull(),
                DatePicker::make('transacted_at')
                    ->label('Tanggal')
                    ->default(today())
                    ->required(),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                Select::make('cash_account_id')
                    ->label('Rekening / Kas')
                    ->relationship('cashAccount', 'name')
                    ->preload(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cashAccount'))
            ->defaultSort('transacted_at', 'desc')
            ->description('Setoran modal menambah kas & ekuitas; prive (uang konveksi dipakai pribadi pemilik) mengurangi keduanya. Bukan beban, jadi tidak mengurangi laba.')
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EquityTransaction::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === EquityTransaction::DEPOSIT ? 'success' : 'warning'),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Summarizer::make()
                        ->label('Modal bersih (setoran - prive)')
                        ->using(fn (QueryBuilder $query): float => (float) $query->sum(DB::raw("CASE WHEN type = 'prive' THEN -amount ELSE amount END")))
                        ->formatStateUsing(fn ($state): string => Number::currency((float) $state, 'IDR', 'id', 0))),
                TextColumn::make('cashAccount.name')
                    ->label('Rekening')
                    ->placeholder('-'),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(EquityTransaction::TYPES),
                DateRangeFilter::make('period', 'Periode', fn (Builder $query, ?string $from, ?string $until): Builder => $query
                    ->when($from, fn (Builder $query) => $query->whereDate('transacted_at', '>=', $from))
                    ->when($until, fn (Builder $query) => $query->whereDate('transacted_at', '<=', $until))),
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
            'index' => ManageEquityTransactions::route('/'),
        ];
    }
}
