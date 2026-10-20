<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SalesDashboard;
use App\Models\Setting;
use App\Services\SalesDashboardService;
use Filament\Actions\Action;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * PERINGATAN RETUR di dashboard utama: SKU dengan % retur 30 hari terakhir >= batas pengaturan.
 * Hanya tampil bila ada SKU yang melewati batas.
 */
class ReturnAlerts extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::alerts()->isNotEmpty();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function alerts(): Collection
    {
        $threshold = (float) Setting::valueOf('return_alert_pct', 0.10);
        $minQty = (int) Setting::valueOf('return_alert_min_qty', 3);

        return app(SalesDashboardService::class)
            ->build(['days' => 30, 'ref_date' => today()->toDateString()])['returns']['by_variant']
            ->filter(fn (array $row): bool => $row['returned'] >= $minQty && ($row['return_rate'] ?? 0) >= $threshold)
            ->sortByDesc('return_rate')
            ->values()
            ->map(fn (array $row, int $index): array => [...$row, '__key' => (string) $row['id']]);
    }

    public function table(Table $table): Table
    {
        $threshold = (float) Setting::valueOf('return_alert_pct', 0.10);

        return $table
            ->heading('Peringatan Retur Tinggi')
            ->description('SKU dengan tingkat retur ≥ '.number_format($threshold * 100, 0).'% dalam 30 hari terakhir (minimal '.(int) Setting::valueOf('return_alert_min_qty', 3).' pcs). Batas diatur di Pengaturan: return_alert_pct & return_alert_min_qty.')
            ->records(fn (): array => self::alerts()->all())
            ->paginated(false)
            ->striped()
            ->columns([
                TextColumn::make('sku')->label('SKU')->size(TextSize::Small)->weight('medium'),
                TextColumn::make('name')->label('Nama Produk')->size(TextSize::Small)->wrap(),
                TextColumn::make('sold_gross')->label('Terjual')->size(TextSize::Small)->numeric(locale: 'id')->alignEnd(),
                TextColumn::make('returned')->label('Retur')->size(TextSize::Small)->numeric(locale: 'id')->alignEnd()->color('danger')->weight('bold'),
                TextColumn::make('return_rate')->label('% Retur')->size(TextSize::Small)->alignEnd()->color('danger')->weight('bold')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 1, ',', '.').'%'),
                TextColumn::make('top_reason')->label('Alasan Terbanyak')->size(TextSize::Small)->badge()->color('warning')->placeholder('Belum diisi'),
            ])
            ->headerActions([
                Action::make('openDashboard')
                    ->label('Analisa Retur')
                    ->icon(Heroicon::OutlinedPresentationChartBar)
                    ->color('gray')
                    ->url(SalesDashboard::getUrl()),
            ]);
    }
}
