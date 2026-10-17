<?php

namespace App\Filament\Resources\StoreMonthlyProfits;

use App\Filament\Resources\StoreMonthlyProfits\Pages\ManageStoreMonthlyProfits;
use App\Models\StoreMonthlyProfit;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StoreMonthlyProfitResource extends Resource
{
    protected static ?string $model = StoreMonthlyProfit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Laba Rugi Toko';

    protected static ?string $pluralModelLabel = 'Laba Rugi Toko';

    protected static ?string $slug = 'laporan/laba-rugi-toko';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('store.reseller'))
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('month')
                    ->label('Bulan')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('store.name')
                    ->label('Toko')
                    ->description(fn (StoreMonthlyProfit $record): ?string => $record->store?->reseller?->name),
                TextColumn::make('revenue')
                    ->label('Pendapatan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('cost_of_goods')
                    ->label('Tagihan Konveksi')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('gross_profit')
                    ->label('Laba Kotor')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('admin_fee')
                    ->label('Fee Admin')
                    ->money('IDR', decimalPlaces: 0)
                    ->description(fn (StoreMonthlyProfit $record): ?string => $record->admin_fee_estimated ? 'estimasi' : null)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('ads')
                    ->label('Iklan')
                    ->money('IDR', decimalPlaces: 0)
                    ->description(fn (StoreMonthlyProfit $record): ?string => $record->ads_estimated ? 'estimasi' : null)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('net_profit')
                    ->label('Laba Bersih')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
                SelectFilter::make('store')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('year')
                    ->schema([
                        TextInput::make('year')
                            ->label('Tahun')
                            ->integer()
                            ->default(now()->year),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['year'],
                        fn (Builder $query, $year) => $query->whereYear('month', $year),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStoreMonthlyProfits::route('/'),
        ];
    }
}
