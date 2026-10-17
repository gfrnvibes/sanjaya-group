<?php

namespace App\Filament\Resources\Resellers\Schemas;

use App\Models\Reseller;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResellerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Reseller')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('owner.name')
                            ->label('Pemilik'),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('opening_balance')
                            ->label('Saldo Tagihan Awal')
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('opening_balance_date')
                            ->label('Tanggal Saldo Awal')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('deleted_at')
                            ->label('Dihapus')
                            ->dateTime()
                            ->visible(fn (Reseller $record): bool => $record->trashed()),
                    ]),
                Section::make('Piutang')
                    ->description('Saldo awal + tagihan penjualan - kredit retur - pembayaran.')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('receivable.billed')
                            ->label('Total Tagihan')
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('receivable.returns_credit')
                            ->label('Kredit Retur')
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('receivable.paid')
                            ->label('Sudah Dibayar')
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('receivable.outstanding')
                            ->label('Sisa Piutang')
                            ->money('IDR', decimalPlaces: 0)
                            ->weight('bold')
                            ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'success'),
                    ]),
            ]);
    }
}
