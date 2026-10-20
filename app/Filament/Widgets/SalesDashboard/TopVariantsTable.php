<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class TopVariantsTable extends DashboardTableWidget
{
    protected static ?int $sort = 3;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['top_variants'];
    }

    protected function searchable(): array
    {
        return ['sku', 'name', 'model'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('B. Varian (SKU) Terlaris')
            ->description('Urut terjual terbanyak dalam periode.')
            ->columns([
                Columns::rank(),
                Columns::text('sku', 'SKU')->weight('medium'),
                Columns::text('name', 'Nama Produk')->wrap(),
                Columns::text('model', 'Model')->color('gray')->toggleable(),
                Columns::text('category', 'Kategori')->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Columns::number('sold', 'Terjual (pcs)')->weight('bold'),
                Columns::money('bill', 'Omzet')->toggleable(),
                Columns::stock(),
                Columns::number('in_production', 'Dalam Produksi')->color('gray'),
                Columns::daysOfStock(),
                Columns::priority(),
            ]);
    }
}
