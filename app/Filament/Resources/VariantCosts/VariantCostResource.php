<?php

namespace App\Filament\Resources\VariantCosts;

use App\Enums\VariantCostSource;
use App\Filament\Resources\VariantCosts\Pages\ManageVariantCosts;
use App\Models\VariantCost;
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

class VariantCostResource extends Resource
{
    protected static ?string $model = VariantCost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'HPP Varian';

    protected static ?string $pluralModelLabel = 'Riwayat HPP';

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
                    ->default(today())
                    ->required(),
                TextInput::make('material_cost')
                    ->label('Biaya Bahan / pcs')
                    ->numeric()
                    ->prefix('Rp')
                    ->default(0)
                    ->required(),
                TextInput::make('labor_cost')
                    ->label('Ongkos Kerja / pcs')
                    ->numeric()
                    ->prefix('Rp')
                    ->default(0)
                    ->required(),
                Select::make('source')
                    ->label('Sumber')
                    ->options(VariantCostSource::class)
                    ->default(VariantCostSource::Manual)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('variant'))
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
                TextColumn::make('material_cost')
                    ->label('Bahan')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('labor_cost')
                    ->label('Ongkos')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('total_cost')
                    ->label('HPP')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold'),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(VariantCostSource::class),
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
     * HPP dihitung dari resep (PricingService::syncPrice), tidak diisi manual.
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
     * HPP yang menjadi acuan harga reseller tidak boleh dihapus.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record->priceHistories()->doesntExist();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVariantCosts::route('/'),
        ];
    }
}
