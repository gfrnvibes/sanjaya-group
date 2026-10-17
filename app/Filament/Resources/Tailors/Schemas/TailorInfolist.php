<?php

namespace App\Filament\Resources\Tailors\Schemas;

use App\Enums\WorkOrderStatus;
use App\Models\Tailor;
use App\Models\WorkOrderItem;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class TailorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Penjahit')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('phone')
                            ->label('Telepon')
                            ->placeholder('-'),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                    ]),
                Section::make('Upah')
                    ->description('Ongkos kerja = qty jadi x ongkos/pcs (snapshot SPK) pada SPK selesai.')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('open_orders')
                            ->label('SPK Berjalan')
                            ->state(fn (Tailor $record): int => $record->workOrders()
                                ->whereIn('status', [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing])
                                ->count()),
                        TextEntry::make('labor_earned')
                            ->label('Total Ongkos Kerja')
                            ->state(fn (Tailor $record): float => self::laborEarned($record))
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('paid')
                            ->label('Sudah Dibayar')
                            ->state(fn (Tailor $record): float => (float) $record->payments()->sum('amount'))
                            ->money('IDR', decimalPlaces: 0),
                        TextEntry::make('outstanding')
                            ->label('Sisa Upah')
                            ->state(fn (Tailor $record): float => self::laborEarned($record) - (float) $record->payments()->sum('amount'))
                            ->money('IDR', decimalPlaces: 0)
                            ->weight('bold')
                            ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'success'),
                    ]),
            ]);
    }

    private static function laborEarned(Tailor $record): float
    {
        return (float) WorkOrderItem::query()
            ->whereHas('workOrder', fn ($query) => $query
                ->where('tailor_id', $record->id)
                ->where('status', WorkOrderStatus::Done))
            ->sum(DB::raw('qty_good * COALESCE(labor_cost_snapshot, 0)'));
    }
}
