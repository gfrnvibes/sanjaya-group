<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ReturnStoresTable extends DashboardTableWidget
{
    protected static ?int $sort = 24;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['returns']['by_store'];
    }

    protected function searchable(): array
    {
        return ['store'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('Retur per Toko')
            ->columns([
                Columns::rank(),
                Columns::text('store', 'Toko')->weight('medium'),
                Columns::number('sold', 'Terjual'),
                Columns::number('returned', 'Retur')->color('danger')->weight('bold'),
                Columns::percent('rate', '% Retur'),
                Columns::money('returned_value', 'Nilai Retur'),
            ]);
    }
}
