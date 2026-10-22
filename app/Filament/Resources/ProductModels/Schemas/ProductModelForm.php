<?php

namespace App\Filament\Resources\ProductModels\Schemas;

use App\Enums\ProductKind;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Model')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode Model')
                            ->helperText('Awalan SKU, mis. BC1.')
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Nama Model')
                            ->required()
                            ->maxLength(150),
                        Select::make('category_id')
                            ->label('Kategori')
                            ->relationship('category', 'name')
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->required()
                                    ->maxLength(60),
                            ]),
                        Select::make('kind')
                            ->label('Jenis')
                            ->options(ProductKind::class)
                            ->default(ProductKind::Produksi)
                            ->required(),
                        TextInput::make('default_margin_pct')
                            ->label('Margin Default')
                            ->helperText('Kosong = pakai pengaturan margin_default (produksi) atau margin_ready_made_pct + margin_ready_made_amount (beli jadi).')
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
