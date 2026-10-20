<?php

namespace App\Filament\Resources\VariantPricings;

use App\Filament\Resources\VariantPricings\Pages\ManageVariantPricings;
use App\Filament\Support\PriceSyncNotification;
use App\Models\VariantPricing;
use App\Services\PricingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class VariantPricingResource extends Resource
{
    protected static ?string $model = VariantPricing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'Usulan Harga';

    protected static ?string $pluralModelLabel = 'Usulan Harga';

    protected static ?string $slug = 'usulan-harga';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sku')
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('hpp')
                    ->label('HPP Berlaku')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('Belum ada'),
                TextColumn::make('margin_pct')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%'),
                TextColumn::make('current_price')
                    ->label('Harga Berlaku')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('Belum ada'),
                TextColumn::make('proposed_price')
                    ->label('Usulan')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold')
                    ->placeholder('-'),
                TextColumn::make('difference')
                    ->label('Selisih')
                    ->state(fn (VariantPricing $record): ?float => $record->current_price && $record->proposed_price
                        ? ((float) $record->proposed_price - (float) $record->current_price) / (float) $record->current_price * 100
                        : null)
                    ->formatStateUsing(fn ($state): string => ($state > 0 ? '+' : '').number_format((float) $state, 1, ',', '.').'%')
                    ->color(fn ($state): string => $state > 0 ? 'danger' : ($state < 0 ? 'success' : 'gray'))
                    ->badge()
                    ->placeholder('-'),
            ])
            ->filters([
                Filter::make('needs_update')
                    ->label('Perlu update harga')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('proposed_price')
                        ->where(fn (Builder $query) => $query->whereNull('current_price')->orWhereColumn('proposed_price', '!=', 'current_price'))),
                Filter::make('without_cost')
                    ->label('Belum ada HPP')
                    ->query(fn (Builder $query): Builder => $query->whereNull('hpp')),
            ])
            ->recordActions([
                Action::make('recalculate')
                    ->label('Hitung Ulang')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Hitung HPP dari resep x harga beli bahan terakhir dan perbarui harga reseller otomatis, berlaku hari ini.')
                    ->action(fn (VariantPricing $record, PricingService $pricing) => PriceSyncNotification::send(
                        $pricing->syncVariants([$record->variant], today()),
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('recalculateSelected')
                    ->label('Hitung Ulang Terpilih')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->requiresConfirmation()
                    ->action(fn (Collection $records, PricingService $pricing) => PriceSyncNotification::send(
                        $pricing->syncVariants($records->load('variant')->pluck('variant')->filter(), today()),
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVariantPricings::route('/'),
        ];
    }
}
