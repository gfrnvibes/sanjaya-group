<?php

namespace App\Filament\Resources\ResellerPriceHistories;

use App\Enums\PriceSource;
use App\Filament\Resources\ResellerPriceHistories\Pages\ManageResellerPriceHistories;
use App\Models\ResellerPriceHistory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ResellerPriceHistoryResource extends Resource
{
    protected static ?string $model = ResellerPriceHistory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'Harga Reseller';

    protected static ?string $pluralModelLabel = 'Riwayat Harga Reseller';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('variant_id')
                    ->label('Varian')
                    ->relationship('variant', 'sku')
                    ->searchable()
                    ->required()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                DatePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->default(today()->addDay())
                    ->required(),
                TextInput::make('base_price')
                    ->label('Harga Dasar')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->required(),
                TextInput::make('margin_pct')
                    ->label('Margin')
                    ->numeric()
                    ->suffix('%')
                    ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                    ->dehydrateStateUsing(fn ($state): ?float => blank($state) ? null : (float) $state / 100),
                Select::make('source')
                    ->label('Sumber')
                    ->options(PriceSource::class)
                    ->default(PriceSource::Manual)
                    ->required(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['variant', 'variantCost', 'approver']))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('base_price')
                    ->label('Harga Dasar')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold'),
                TextColumn::make('variantCost.total_cost')
                    ->label('HPP Acuan')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-'),
                TextColumn::make('margin_pct')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                    ->placeholder('-'),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
                TextColumn::make('approver.name')
                    ->label('Disetujui')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(PriceSource::class),
                SelectFilter::make('variant')
                    ->label('Varian')
                    ->relationship('variant', 'sku')
                    ->searchable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Harga dasar dibuat otomatis dari resep + margin (PricingService::syncPrice), tidak diisi manual.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Harga yang sudah dipakai snapshot penjualan tidak boleh dihapus.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record->salesOrderItems()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResellerPriceHistories::route('/'),
        ];
    }
}
