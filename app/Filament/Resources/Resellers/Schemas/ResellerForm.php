<?php

namespace App\Filament\Resources\Resellers\Schemas;

use App\Models\Reseller;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResellerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Reseller')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('owner_id')
                            ->disabled(fn (?Reseller $record): bool => $record?->stores()->exists() ?? false)
                            ->label('Pemilik')
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Nama Reseller / Brand')
                            ->required()
                            ->maxLength(120)
                            ->unique(ignoreRecord: true),
                        TextInput::make('opening_balance')
                            ->label('Saldo Tagihan Awal')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->required(),
                        DatePicker::make('opening_balance_date')
                            ->label('Tanggal Saldo Awal'),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }
}
