<?php

namespace App\Filament\Resources\PriceTiers;

use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Models\PriceTier;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PriceTierResource extends Resource
{
    protected static ?string $model = PriceTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Reseller & Toko';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Tier Harga';

    protected static ?string $pluralModelLabel = 'Tier Harga';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(60),
                TextInput::make('adjustment_pct')
                    ->label('Penyesuaian dari Harga Dasar')
                    ->helperText('5 = harga dasar +5%, -3 = harga dasar -3%.')
                    ->numeric()
                    ->suffix('%')
                    ->default(0)
                    ->required()
                    ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                    ->dehydrateStateUsing(fn ($state): float => (float) $state / 100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('ownerTierHistories'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('adjustment_pct')
                    ->label('Penyesuaian')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                    ->sortable(),
                TextColumn::make('owner_tier_histories_count')
                    ->label('Dipakai (riwayat)'),
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
            'index' => ManagePriceTiers::route('/'),
        ];
    }
}
