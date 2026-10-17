<?php

namespace App\Filament\Resources\ResellerReceivables;

use App\Filament\Resources\ResellerReceivables\Pages\ManageResellerReceivables;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerReceivable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ResellerReceivableResource extends Resource
{
    protected static ?string $model = ResellerReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Piutang Reseller';

    protected static ?string $pluralModelLabel = 'Piutang Reseller';

    protected static ?string $slug = 'laporan/piutang-reseller';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('owner'))
            ->defaultSort('outstanding', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Reseller')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Pemilik'),
                TextColumn::make('opening_balance')
                    ->label('Saldo Awal')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('billed')
                    ->label('Tagihan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('returns_credit')
                    ->label('Kredit Retur')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('paid')
                    ->label('Dibayar')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('outstanding')
                    ->label('Sisa Piutang')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'success')
                    ->sortable()
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
                SelectFilter::make('owner')
                    ->label('Pemilik')
                    ->relationship('owner', 'name')
                    ->preload(),
                Filter::make('has_outstanding')
                    ->label('Masih ada piutang')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('outstanding', '>', 0)),
            ])
            ->recordActions([
                Action::make('openReseller')
                    ->label('Detail')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ResellerReceivable $record): string => ResellerResource::getUrl('view', ['record' => $record->reseller_id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResellerReceivables::route('/'),
        ];
    }
}
