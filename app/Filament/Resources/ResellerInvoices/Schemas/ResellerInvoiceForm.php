<?php

namespace App\Filament\Resources\ResellerInvoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResellerInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tagihan')
                    ->description('Saat disimpan, semua baris penjualan & retur reseller dalam periode yang belum ditagih otomatis masuk ke tagihan ini.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('reseller_id')
                            ->label('Reseller')
                            ->relationship('reseller', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabledOn('edit'),
                        DatePicker::make('period_start')
                            ->label('Periode Mulai')
                            ->default(today()->startOfMonth())
                            ->required()
                            ->disabledOn('edit'),
                        DatePicker::make('period_end')
                            ->label('Periode Akhir')
                            ->default(today()->endOfMonth())
                            ->afterOrEqual('period_start')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('invoice_no')
                            ->label('No. Tagihan')
                            ->helperText('Kosongkan untuk nomor otomatis INV/YYYYMM/0001.')
                            ->maxLength(40)
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo'),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
