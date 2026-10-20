<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ReturnReasonsTable extends DashboardTableWidget
{
    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['returns']['by_reason'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('Retur per Alasan')
            ->description('Cacat jahitan / bahan = bahan evaluasi kualitas produksi.')
            ->columns([
                Columns::rank(),
                Columns::text('reason', 'Alasan')->weight('medium'),
                Columns::number('returned', 'Retur (pcs)')->color('danger')->weight('bold'),
                Columns::percent('share', 'Porsi', warnFrom: 2),
                Columns::number('sku_count', 'SKU'),
                Columns::money('returned_value', 'Nilai Retur'),
            ]);
    }
}
