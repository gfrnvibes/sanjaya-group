<?php

namespace App\Imports;

use App\Models\ProductCategory;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Kategori produk: dicocokkan lewat nama; nama yang belum ada dibuat. Ganti nama lewat kolom NAMA BARU.
 */
class ProductCategoryImport extends RowImporter
{
    public function title(): string
    {
        return 'Kategori Produk';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'KATEGORI' => ['name', 'Ya', 'Nama kategori. Belum ada = dibuat.'],
            'NAMA BARU' => ['new_name', '-', 'Isi untuk mengganti nama kategori yang sudah ada.'],
            'JUMLAH MODEL' => ['model_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['JUMLAH MODEL'];
    }

    protected function requiredHeaders(): array
    {
        return ['name'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['name' => 'KATEGORI']);
        $row->maxLength(['name' => ['KATEGORI', 60], 'new_name' => ['NAMA BARU', 60]]);
        $row->throwIfInvalid();

        $category = $this->findCi(ProductCategory::query(), 'name', $row->text('name'));

        if ($category === null) {
            ProductCategory::create(['name' => $row->text('new_name') ?: $row->text('name')]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($category->fill(array_filter(['name' => $row->text('new_name') ?: null])));
    }

    protected function exportRows(): iterable
    {
        foreach (ProductCategory::query()->withCount('models')->orderBy('name')->lazy() as $category) {
            yield [$category->name, null, $category->models_count];
        }
    }
}
