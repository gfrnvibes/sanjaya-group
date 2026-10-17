<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Toko')
                    ->description('1 toko = 1 reseller di 1 channel, mis. "ARR SANJAYA Shopee".')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('reseller_id')
                            ->label('Reseller')
                            ->relationship('reseller', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('channel_id')
                            ->label('Channel')
                            ->relationship('channel', 'name')
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Nama Toko')
                            ->required()
                            ->maxLength(120)
                            ->unique(ignoreRecord: true),
                        TextInput::make('external_ref')
                            ->label('ID Toko BigSeller / Marketplace')
                            ->maxLength(120),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }
}
