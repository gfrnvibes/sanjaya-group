<?php

namespace App\Imports;

use App\Models\ExpenseCategory;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Kategori biaya: dicocokkan lewat kode; yang sudah ada diperbarui dari sel yang terisi.
 */
class ExpenseCategoryImport extends RowImporter
{
    public function title(): string
    {
        return 'Kategori Biaya';
    }

    public function permission(): string
    {
        return 'kelola keuangan';
    }

    public function columns(): array
    {
        return [
            'KODE KATEGORI' => ['code', 'Ya', 'Kode unik, mis. LISTRIK_AIR, SEWA, GAJI_STAF, PACKING.'],
            'NAMA KATEGORI' => ['name', 'Kategori baru', 'Default = kode.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE KATEGORI']);
        $row->maxLength(['code' => ['KODE KATEGORI', 30], 'name' => ['NAMA KATEGORI', 80]]);
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $category = $this->findCi(ExpenseCategory::query(), 'code', $code);

        if ($category === null) {
            ExpenseCategory::create(['code' => $code, 'name' => $row->text('name') ?: $code]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($category->fill(array_filter(['name' => $row->text('name') ?: null])));
    }

    protected function exportRows(): iterable
    {
        foreach (ExpenseCategory::query()->orderBy('code')->lazy() as $category) {
            yield [$category->code, $category->name];
        }
    }
}
