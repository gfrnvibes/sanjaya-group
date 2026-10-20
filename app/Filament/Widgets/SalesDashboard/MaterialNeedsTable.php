<?php

namespace App\Filament\Widgets\SalesDashboard;

use App\Services\SalesDashboardService;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * D. Kebutuhan bahan untuk seluruh saran produksi (resep x saran) dibanding stok bahan.
 */
class MaterialNeedsTable extends DashboardTableWidget
{
    protected static ?int $sort = 5;

    protected function rows(array $dashboard): Collection
    {
        return $this->needs()['rows'];
    }

    protected function searchable(): array
    {
        return ['code'];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, missing: list<string>}
     */
    private function needs(): array
    {
        return app(SalesDashboardService::class)->materialNeeds($this->pageFilters ?? []);
    }

    public function table(Table $table): Table
    {
        $needs = $this->needs();
        $short = $needs['rows']->where('status', 'KURANG')->count();
        $missing = count($needs['missing']);

        return $this->baseTable($table)
            ->heading('D. Kebutuhan Bahan untuk Saran Produksi')
            ->description("Resep × saran produksi (tabel C), dibanding saldo bahan semua gudang. {$short} bahan kurang."
                .($missing > 0 ? " {$missing} SKU belum bisa dihitung (resep/bahan warna belum lengkap): ".collect($needs['missing'])->take(5)->implode(', ').($missing > 5 ? ', …' : '') : ''))
            ->columns([
                Columns::rank(),
                Columns::text('code', 'Bahan')->weight('medium'),
                Columns::number('skus', 'Dipakai SKU'),
                Columns::number('needed', 'Kebutuhan', 1),
                Columns::number('stock', 'Stok Bahan', 1)->color(fn ($state): ?string => $state < 0 ? 'danger' : null),
                Columns::number('shortage', 'Kekurangan', 1)->color(fn ($state): ?string => $state > 0 ? 'danger' : null)->weight('bold'),
                Columns::text('uom', 'Satuan')->color('gray'),
                TextColumn::make('packs_to_buy')
                    ->label('Perlu Beli')
                    ->size(TextSize::Small)
                    ->formatStateUsing(fn ($state, array $record): string => $state > 0 ? number_format($state, 0, ',', '.').' '.$record['pack_uom'] : '-')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->size(TextSize::ExtraSmall)
                    ->color(fn (string $state): string => $state === 'KURANG' ? 'danger' : 'success')
                    ->sortable(),
            ]);
    }
}
