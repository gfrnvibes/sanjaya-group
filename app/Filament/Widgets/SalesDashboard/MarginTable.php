<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * E. Margin konveksi per model: tagihan reseller vs HPP barang terjual (snapshot saat penjualan).
 */
class MarginTable extends DashboardTableWidget
{
    protected static ?int $sort = 6;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['margins'];
    }

    protected function searchable(): array
    {
        return ['model', 'category'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('E. Margin per Model')
            ->description('Laba kotor konveksi = tagihan reseller − HPP barang terjual. Margin rendah pada model laris = kandidat penyesuaian harga/resep.')
            ->columns([
                Columns::rank(),
                Columns::text('model', 'Model')->weight('medium'),
                Columns::text('category', 'Kategori')->color('gray'),
                Columns::number('sold', 'Terjual (pcs)'),
                Columns::money('bill', 'Omzet (Tagihan)'),
                Columns::money('hpp', 'HPP Terjual'),
                Columns::money('profit', 'Laba Kotor')->weight('bold')->color(fn ($state): string => $state < 0 ? 'danger' : 'success'),
                Columns::percent('margin', 'Margin')->color(fn ($state): ?string => $state !== null && $state < 0.15 ? 'danger' : 'success'),
                Columns::money('profit_per_pcs', 'Laba / pcs'),
            ]);
    }
}
