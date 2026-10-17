<?php

namespace App\Filament\Resources\StoreAdSpends;

use App\Filament\Resources\StoreAdSpends\Pages\ManageStoreAdSpends;
use App\Models\StoreAdSpend;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class StoreAdSpendResource extends Resource
{
    protected static ?string $model = StoreAdSpend::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Biaya Iklan';

    protected static ?string $pluralModelLabel = 'Biaya Iklan Toko';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                DatePicker::make('spend_date')
                    ->label('Tanggal')
                    ->default(today())
                    ->required(),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->required(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->defaultSort('spend_date', 'desc')
            ->columns([
                TextColumn::make('spend_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('store.name')
                    ->label('Toko')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('store')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),
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
            'index' => ManageStoreAdSpends::route('/'),
        ];
    }
}
