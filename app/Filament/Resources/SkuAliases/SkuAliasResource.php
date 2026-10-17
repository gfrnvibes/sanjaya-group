<?php

namespace App\Filament\Resources\SkuAliases;

use App\Filament\Resources\SkuAliases\Pages\ManageSkuAliases;
use App\Models\SkuAlias;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SkuAliasResource extends Resource
{
    protected static ?string $model = SkuAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Produk';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Pemetaan SKU';

    protected static ?string $pluralModelLabel = 'Pemetaan SKU';

    protected static ?string $recordTitleAttribute = 'alias';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('alias')
                    ->label('SKU di BigSeller / Marketplace')
                    ->required()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true),
                Select::make('variant_id')
                    ->label('Varian')
                    ->relationship('variant', 'sku')
                    ->searchable()
                    ->required()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                Select::make('source')
                    ->label('Sumber')
                    ->options([
                        'bigseller' => 'BigSeller',
                        'shopee' => 'Shopee',
                        'tiktok' => 'TikTok',
                        'lainnya' => 'Lainnya',
                    ])
                    ->default('bigseller')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('variant'))
            ->columns([
                TextColumn::make('alias')
                    ->label('Alias')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('variant.sku')
                    ->label('SKU Varian')
                    ->searchable(),
                TextColumn::make('variant.name')
                    ->label('Nama Varian')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options([
                        'bigseller' => 'BigSeller',
                        'shopee' => 'Shopee',
                        'tiktok' => 'TikTok',
                        'lainnya' => 'Lainnya',
                    ]),
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
            'index' => ManageSkuAliases::route('/'),
        ];
    }
}
