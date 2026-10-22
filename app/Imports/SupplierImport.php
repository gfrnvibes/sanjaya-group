<?php

namespace App\Imports;

use App\Models\Supplier;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Supplier (bahan & beli jadi): dicocokkan lewat nama; yang sudah ada diperbarui dari sel yang terisi.
 */
class SupplierImport extends RowImporter
{
    public function title(): string
    {
        return 'Supplier';
    }

    public function columns(): array
    {
        return [
            'NAMA SUPPLIER' => ['name', 'Ya', 'Supplier yang sudah ada (nama sama) diperbarui.'],
            'NO HP' => ['phone', '-', 'Format kolom sebagai teks agar angka 0 di depan tidak hilang.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['name'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['name' => 'NAMA SUPPLIER']);
        $row->maxLength(['name' => ['NAMA SUPPLIER', 120], 'phone' => ['NO HP', 30]]);
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $attributes = array_filter(['phone' => $row->text('phone') ?: null, 'is_active' => $active], fn ($value): bool => $value !== null);
        $supplier = $this->findCi(Supplier::query(), 'name', $row->text('name'));

        if ($supplier === null) {
            Supplier::create(['name' => $row->text('name'), ...$attributes]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($supplier->fill($attributes));
    }

    protected function exportRows(): iterable
    {
        foreach (Supplier::query()->orderBy('name')->lazy() as $supplier) {
            yield [$supplier->name, $supplier->phone, $supplier->is_active ? 'Ya' : 'Tidak'];
        }
    }
}
