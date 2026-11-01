<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\ResellerReceivable;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Reseller dengan sisa piutang terbesar.
 */
class TopReceivables extends TableWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 12;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Piutang Reseller Terbesar')
            ->query(ResellerReceivable::query()->with('reseller:id,name')->where('outstanding', '>', 0))
            ->defaultSort('outstanding', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->striped()
            ->columns([
                TextColumn::make('reseller.name')->label('Reseller')->size(TextSize::Small)->weight('medium')->searchable(),
                TextColumn::make('billed')->label('Ditagih')->size(TextSize::Small)->money('IDR', decimalPlaces: 0)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid')->label('Dibayar')->size(TextSize::Small)->money('IDR', decimalPlaces: 0)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outstanding')->label('Sisa Piutang')->size(TextSize::Small)->money('IDR', decimalPlaces: 0)->alignEnd()->sortable()->weight('bold')->color('warning'),
            ])
            ->emptyStateHeading('Tidak ada piutang')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
