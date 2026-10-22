<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\SkuAlias;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pemetaan SKU BigSeller/marketplace -> varian. Header sama dengan export tabel Pemetaan SKU,
 * jadi hasil export bisa diedit lalu diimpor ulang. Dicocokkan lewat ALIAS (tanpa beda huruf besar/kecil).
 */
class SkuAliasImport extends RowImporter
{
    public function title(): string
    {
        return 'Pemetaan SKU';
    }

    public function permission(): string
    {
        return 'kelola penjualan';
    }

    public function columns(): array
    {
        return [
            'ALIAS' => ['alias', 'Ya', 'SKU di BigSeller / marketplace. Yang sudah ada diperbarui (varian & sumbernya).'],
            'SKU VARIAN' => ['sku', 'Ya', 'SKU varian di sistem (lihat sheet Daftar SKU).'],
            'NAMA VARIAN' => ['name', '-', 'Info dari sistem, tidak perlu diisi.'],
            'SUMBER' => ['source', '-', implode(' / ', SkuAlias::SOURCES).'. Default BigSeller.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['NAMA VARIAN'];
    }

    protected function requiredHeaders(): array
    {
        return ['alias', 'sku'];
    }

    protected function notes(): array
    {
        return [
            'Satu ALIAS hanya untuk satu varian; satu varian boleh punya banyak ALIAS.',
            'Menghapus baris di Excel tidak menghapus pemetaan; hapus lewat menu Pemetaan SKU.',
        ];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar SKU' => [[18, 50], [
                ['SKU VARIAN', 'NAMA VARIAN'],
                ...ProductVariant::query()->where('is_active', true)->orderBy('sku')->get(['sku', 'name'])
                    ->map(fn (ProductVariant $variant): array => [$variant->sku, $variant->name])
                    ->all(),
            ]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['alias' => 'ALIAS', 'sku' => 'SKU VARIAN']);
        $row->maxLength(['alias' => ['ALIAS', 120]]);
        $source = $this->source($row);
        $row->throwIfInvalid();

        $variant = $this->findCi(ProductVariant::query(), 'sku', $row->text('sku'))
            ?? throw new BusinessRuleException("SKU VARIAN {$row->text('sku')} tidak ditemukan");
        $alias = $this->findCi(SkuAlias::query(), 'alias', $row->text('alias'));

        if ($alias === null) {
            SkuAlias::create(['alias' => $row->text('alias'), 'variant_id' => $variant->id, 'source' => $source ?? 'bigseller']);
            $this->bump('created');

            return;
        }

        if ($alias->variant_id !== $variant->id) {
            $this->warn("{$alias->alias} dipindah ke varian {$variant->sku}; pesanan baru memakai varian ini.");
        }

        $this->saveIfDirty($alias->fill(array_filter(['variant_id' => $variant->id, 'source' => $source])));
    }

    protected function exportRows(): iterable
    {
        foreach (SkuAlias::query()->with('variant')->orderBy('alias')->lazy() as $alias) {
            yield [$alias->alias, $alias->variant?->sku, $alias->variant?->name, SkuAlias::SOURCES[$alias->source] ?? $alias->source];
        }
    }

    /**
     * Kode atau label sumber (mis. "bigseller" / "BigSeller"). Null = kosong (pakai default / tetap).
     */
    private function source(SpreadsheetRow $row): ?string
    {
        if (! $row->filled('source')) {
            return null;
        }

        $value = Str::lower($row->text('source'));
        $code = collect(SkuAlias::SOURCES)->search(fn (string $label, string $code): bool => $code === $value || Str::lower($label) === $value);

        if ($code === false) {
            throw new BusinessRuleException('SUMBER harus salah satu: '.implode(', ', SkuAlias::SOURCES));
        }

        return $code;
    }
}
