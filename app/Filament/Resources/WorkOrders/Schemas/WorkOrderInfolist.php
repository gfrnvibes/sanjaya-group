<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\WageType;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data SPK')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('No. SPK'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (WorkOrderStatus $state): string => match ($state) {
                                WorkOrderStatus::Cutting => 'warning',
                                WorkOrderStatus::Sewing => 'info',
                                WorkOrderStatus::Done => 'success',
                                WorkOrderStatus::Cancelled => 'danger',
                            }),
                        TextEntry::make('tailor.name')
                            ->label('Penjahit')
                            ->placeholder('-'),
                        TextEntry::make('cutter.name')
                            ->label('Tukang Potong')
                            ->placeholder('-'),
                        TextEntry::make('warehouse.name')
                            ->label('Gudang Bahan'),
                        TextEntry::make('goodsWarehouse.name')
                            ->label('Gudang Barang Jadi')
                            ->placeholder('Sama dengan gudang bahan'),
                        TextEntry::make('cut_date')
                            ->label('Tanggal Potong')
                            ->date(),
                        TextEntry::make('finished_date')
                            ->label('Tanggal Selesai')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('creator.name')
                            ->label('Dibuat Oleh')
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('-'),
                    ]),
                Section::make('Ringkasan')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('qty_cut')
                            ->label('Total Potong')
                            ->state(fn (WorkOrder $record): int => (int) $record->items()->sum('qty_cut')),
                        TextEntry::make('qty_good')
                            ->label('Total Jadi')
                            ->state(fn (WorkOrder $record): int => (int) $record->items()->sum('qty_good')),
                        TextEntry::make('labor_total')
                            ->label('Upah Jahit (sisa / total)')
                            ->state(fn (WorkOrder $record): string => 'Rp '.number_format($record->laborDue(null, WageType::Jahit), 0, ',', '.').' / Rp '.number_format($record->laborTotal(WageType::Jahit), 0, ',', '.')),
                        TextEntry::make('cutting_total')
                            ->label('Upah Potong (sisa / total)')
                            ->state(fn (WorkOrder $record): string => 'Rp '.number_format($record->laborDue(null, WageType::Potong), 0, ',', '.').' / Rp '.number_format($record->laborTotal(WageType::Potong), 0, ',', '.')),
                        TextEntry::make('paid_total')
                            ->label('Upah Sudah Dibayar')
                            ->state(fn (WorkOrder $record): float => (float) $record->tailorPayments()->sum('amount'))
                            ->money('IDR', decimalPlaces: 0),
                    ]),
            ]);
    }
}
