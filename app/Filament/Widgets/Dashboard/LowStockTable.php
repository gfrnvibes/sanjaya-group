<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\SalesDashboard;
use App\Models\ProductVariant;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Varian aktif dengan stok barang jadi <= stok minimum, paling kurang di atas.
 */
class LowStockTable extends TableWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 13;

    private const STOCK = '(SELECT COALESCE(SUM(qty), 0) FROM stock_balances b WHERE b.variant_id = product_variants.id)';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Stok Menipis')
            ->description('Stok barang jadi ≤ stok minimum varian.')
            ->query(ProductVariant::query()
                ->where('is_active', true)
                ->whereRaw(self::STOCK.' <= min_stock')
                ->select('product_variants.*')
                ->selectRaw(self::STOCK.' AS stock'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(self::STOCK.' - min_stock')->orderBy('sku'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->striped()
            ->columns([
                TextColumn::make('sku')->label('SKU')->size(TextSize::Small)->weight('medium')->searchable(),
                TextColumn::make('name')->label('Nama Produk')->size(TextSize::Small)->wrap()->searchable(),
                TextColumn::make('stock')->label('Stok')->size(TextSize::Small)->numeric(locale: 'id')->alignEnd()
                    ->color(fn ($state): string => (int) $state <= 0 ? 'danger' : 'warning')->weight('bold'),
                TextColumn::make('min_stock')->label('Minimum')->size(TextSize::Small)->numeric(locale: 'id')->alignEnd(),
            ])
            ->headerActions([
                Action::make('priority')
                    ->label('Prioritas Produksi')
                    ->icon(Heroicon::OutlinedPresentationChartBar)
                    ->color('gray')
                    ->url(SalesDashboard::getUrl()),
            ])
            ->emptyStateHeading('Semua stok aman')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
