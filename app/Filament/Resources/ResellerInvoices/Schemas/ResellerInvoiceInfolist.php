<?php

namespace App\Filament\Resources\ResellerInvoices\Schemas;

use App\Enums\ResellerInvoiceStatus;
use App\Models\ResellerInvoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResellerInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tagihan')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('invoice_no')
                            ->label('No. Tagihan')
                            ->copyable(),
                        TextEntry::make('reseller.name')
                            ->label('Reseller'),
                        TextEntry::make('reseller.owner.name')
                            ->label('Pemilik'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (ResellerInvoiceStatus $state): string => self::statusColor($state)),
                        TextEntry::make('period')
                            ->label('Periode')
                            ->state(fn (ResellerInvoice $record): string => $record->period_start->translatedFormat('d M Y').' - '.$record->period_end->translatedFormat('d M Y')),
                        TextEntry::make('issued_at')
                            ->label('Terbit')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('due_date')
                            ->label('Jatuh Tempo')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-'),
                    ]),
                Section::make('Nilai Tagihan')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('billed')
                            ->label('Total Penjualan')
                            ->state(fn (ResellerInvoice $record): float => $record->billedTotal())
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('returns_credit')
                            ->label('Kredit Retur')
                            ->state(fn (ResellerInvoice $record): float => $record->returnsCreditTotal())
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('net')
                            ->label('Total Tagihan')
                            ->state(fn (ResellerInvoice $record): float => $record->netTotal())
                            ->money('IDR', decimalPlaces: 0)
                            ->weight('bold')
                            ->size('lg'),
                    ]),
            ]);
    }

    public static function statusColor(ResellerInvoiceStatus $state): string
    {
        return match ($state) {
            ResellerInvoiceStatus::Draft => 'warning',
            ResellerInvoiceStatus::Issued => 'success',
            ResellerInvoiceStatus::Void => 'danger',
        };
    }
}
