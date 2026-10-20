<?php

namespace App\Filament\Widgets\SalesDashboard;

use App\Services\SalesDashboardService;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dasar tabel di Dashboard Produk: data dari SalesDashboardService (sesuai pengaturan halaman),
 * dengan urut kolom, pencarian, dan paginasi pada data kustom.
 */
abstract class DashboardTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    /** Hanya tampil di Dashboard Produk, tidak ikut dashboard utama. */
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * Baris tabel dari hasil dashboard.
     *
     * @param  array<string, mixed>  $dashboard
     * @return Collection<int, array<string, mixed>>
     */
    abstract protected function rows(array $dashboard): Collection;

    /**
     * Kolom yang dicari oleh kotak pencarian.
     *
     * @return list<string>
     */
    protected function searchable(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dashboard(): array
    {
        return app(SalesDashboardService::class)->build($this->pageFilters ?? []);
    }

    /**
     * Tabel compact standar: paginasi 10/25/50, bergaris, tanpa aksi.
     */
    protected function baseTable(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search): LengthAwarePaginator => $this->paginate(
                $this->rows($this->dashboard()), $page, $recordsPerPage, $sortColumn, $sortDirection, $search,
            ))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->searchable($this->searchable() !== [])
            ->emptyStateHeading('Tidak ada data pada periode ini')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginate(Collection $rows, int $page, int|string $perPage, ?string $sortColumn, ?string $sortDirection, ?string $search): LengthAwarePaginator
    {
        // Nomor urut mengikuti urutan bawaan (terlaris / prioritas), tidak berubah saat diurutkan ulang.
        $rows = $rows->values()->map(fn (array $row, int $index): array => [...$row, 'rank' => $index + 1, '__key' => (string) ($index + 1)]);

        if (filled($search) && $this->searchable() !== []) {
            $needle = Str::lower($search);
            $rows = $rows->filter(fn (array $row): bool => collect($this->searchable())
                ->contains(fn (string $key): bool => str_contains(Str::lower((string) ($row[$key] ?? '')), $needle)));
        }

        if ($sortColumn) {
            $rows = $rows->sortBy(fn (array $row) => $row[$sortColumn] ?? null, SORT_NATURAL, $sortDirection === 'desc');
        }

        $rows = $rows->values();
        $perPage = $perPage === 'all' ? max(1, $rows->count()) : (int) $perPage;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);
    }
}
