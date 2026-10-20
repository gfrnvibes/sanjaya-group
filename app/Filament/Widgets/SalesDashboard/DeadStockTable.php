<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * F. Stok lambat laku: stok ada tapi tidak terjual >= N hari (pengaturan "Stok lambat laku").
 */
class DeadStockTable extends DashboardTableWidget
{
    protected static ?int $sort = 7;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['dead_stock'];
    }

    protected function searchable(): array
    {
        return ['sku', 'name', 'model'];
    }

    public function table(Table $table): Table
    {
        $d = $this->dashboard();

        return $this->baseTable($table)
            ->heading('F. Stok Lambat Laku')
            ->description("Stok ada tetapi tidak terjual ≥ {$d['params']['deadDays']} hari (atau belum pernah terjual). Total modal tertahan: Rp "
                .number_format($d['dead_stock']->sum('stock_value'), 0, ',', '.').'. Pertimbangkan promo, bundling, atau stop produksi.')
            ->columns([
                Columns::rank(),
                Columns::text('sku', 'SKU')->weight('medium'),
                Columns::text('name', 'Nama Produk')->wrap(),
                Columns::text('model', 'Model')->color('gray')->toggleable(),
                Columns::stock(),
                Columns::money('hpp_unit', 'HPP / pcs'),
                Columns::money('stock_value', 'Modal Tertahan')->weight('bold')->color('danger'),
                TextColumn::make('last_sold')
                    ->label('Terakhir Terjual')
                    ->size(TextSize::Small)
                    ->date('d M Y')
                    ->placeholder('Belum pernah')
                    ->sortable(),
                Columns::number('idle_days', 'Tidak Laku (hari)')->placeholder('-'),
            ]);
    }
}
