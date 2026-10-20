<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class TopModelsTable extends DashboardTableWidget
{
    protected static ?int $sort = 2;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['top_models'];
    }

    protected function searchable(): array
    {
        return ['model', 'category'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('A. Produk (Model) Terlaris')
            ->description('Urut terjual terbanyak dalam periode.')
            ->columns([
                Columns::rank(),
                Columns::text('model', 'Model')->weight('medium'),
                Columns::text('category', 'Kategori')->color('gray'),
                Columns::number('sold', 'Terjual (pcs)')->weight('bold'),
                Columns::money('bill', 'Omzet'),
                Columns::stock('stock', 'Stok Sekarang'),
                Columns::number('in_production', 'Dalam Produksi')->color('gray'),
                Columns::daysOfStock(),
                Columns::number('suggested', 'Saran Produksi')->color('primary')->weight('bold'),
            ]);
    }
}
