<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ReturnModelsTable extends DashboardTableWidget
{
    protected static ?int $sort = 23;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['returns']['by_model'];
    }

    protected function searchable(): array
    {
        return ['model'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('Retur per Model')
            ->columns([
                Columns::rank(),
                Columns::text('model', 'Model')->weight('medium'),
                Columns::number('sold', 'Terjual'),
                Columns::number('returned', 'Retur')->color('danger')->weight('bold'),
                Columns::percent('rate', '% Retur'),
                Columns::money('value', 'Nilai Retur'),
            ]);
    }
}
