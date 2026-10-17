<?php

namespace App\Filament\Resources\Channels\Schemas;

use App\Enums\ChannelKind;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Channel')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode')
                            ->helperText('SHOPEE, TIKTOK, LAZADA, WEBSITE, WA, OFFLINE, ...')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(60),
                        Select::make('kind')
                            ->label('Jenis')
                            ->options(ChannelKind::class)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
