<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Varian')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('model_id')
                            ->label('Model')
                            ->relationship('model', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('color_id')
                            ->label('Warna')
                            ->relationship('color', 'name')
                            ->preload(),
                        Select::make('size_id')
                            ->label('Ukuran')
                            ->relationship('size', 'code', fn ($query) => $query->orderBy('sort_order'))
                            ->preload(),
                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(40)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Nama Varian')
                            ->required()
                            ->maxLength(200)
                            ->columnSpan(2),
                        TextInput::make('min_stock')
                            ->label('Stok Minimum')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('margin_pct')
                            ->label('Margin Khusus')
                            ->helperText('Kosong = ikut margin model / default.')
                            ->numeric()
                            ->suffix('%')
                            ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                            ->dehydrateStateUsing(fn ($state): ?float => blank($state) ? null : (float) $state / 100),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
