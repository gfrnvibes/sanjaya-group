<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ReturnVariantsTable extends DashboardTableWidget
{
    protected static ?int $sort = 21;

    protected function rows(array $dashboard): Collection
    {
        return $dashboard['returns']['by_variant'];
    }

    protected function searchable(): array
    {
        return ['sku', 'name', 'model', 'notes'];
    }

    public function table(Table $table): Table
    {
        return $this->baseTable($table)
            ->heading('SKU Paling Banyak Diretur')
            ->description('Berdasarkan tanggal retur dalam periode. % retur ≥ 10% ditandai merah: cek kualitas jahitan/bahan, ukuran, atau deskripsi produk.')
            ->columns([
                Columns::rank(),
                Columns::text('sku', 'SKU')->weight('medium'),
                Columns::text('name', 'Nama Produk')->wrap(),
                Columns::text('model', 'Model')->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Columns::number('sold_gross', 'Terjual'),
                Columns::number('returned', 'Retur (pcs)')->color('danger')->weight('bold'),
                Columns::text('top_reason', 'Alasan Terbanyak')->badge()->color('warning')->placeholder('Belum diisi'),
                Columns::percent('return_rate', '% Retur'),
                Columns::money('returned_value', 'Nilai Retur'),
                Columns::number('restocked', 'Masuk Stok'),
                Columns::text('notes', 'Catatan Retur', sortable: false)->wrap()->color('gray'),
            ]);
    }
}
