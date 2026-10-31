<?php

namespace App\Filament\Resources\Resellers\Schemas;

use App\Enums\InvoiceCycle;
use App\Models\Reseller;
use App\Services\InvoiceSync;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                Section::make('Siklus tagihan otomatis')
                    ->description(fn (): string => 'Kosongkan untuk mengikuti pengaturan umum: '.InvoiceSync::defaultCycle()->describe(InvoiceSync::defaultDay()).'.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Select::make('invoice_cycle')
                            ->label('Siklus')
                            ->options(InvoiceCycle::class)
                            ->placeholder('Ikuti pengaturan umum')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('invoice_cycle_day', 1)),
                        Select::make('invoice_cycle_day')
                            ->label(fn (Get $get): string => self::cycle($get('invoice_cycle')) === InvoiceCycle::Weekly ? 'Mulai hari' : 'Mulai tanggal')
                            ->options(fn (Get $get): array => self::cycle($get('invoice_cycle'))?->dayOptions() ?? [])
                            ->visible(fn (Get $get): bool => self::cycle($get('invoice_cycle')) !== null)
                            ->required(fn (Get $get): bool => self::cycle($get('invoice_cycle')) !== null),
                    ]),
            ]);
    }

    private static function cycle(mixed $state): ?InvoiceCycle
    {
        return $state instanceof InvoiceCycle ? $state : InvoiceCycle::tryFrom((string) $state);
    }
}
