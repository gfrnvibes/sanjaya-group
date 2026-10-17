<?php

namespace App\Filament\Resources\KonveksiMonthlyProfits;

use App\Filament\Resources\KonveksiMonthlyProfits\Pages\ManageKonveksiMonthlyProfits;
use App\Models\KonveksiMonthlyProfit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class KonveksiMonthlyProfitResource extends Resource
{
    protected static ?string $model = KonveksiMonthlyProfit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Laba Rugi Konveksi';

    protected static ?string $pluralModelLabel = 'Laba Rugi Konveksi';

    protected static ?string $slug = 'laporan/laba-rugi-konveksi';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('month')
                    ->label('Bulan')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('net_sales')
                    ->label('Penjualan ke Reseller (net retur)')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('hpp')
                    ->label('HPP')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('gross_profit')
                    ->label('Laba Kotor')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('expenses')
                    ->label('Biaya Operasional')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('net_profit')
                    ->label('Laba Bersih')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
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
            'index' => ManageKonveksiMonthlyProfits::route('/'),
        ];
    }
}
