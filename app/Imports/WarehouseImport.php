<?php

namespace App\Imports;

use App\Models\Warehouse;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gudang: dicocokkan lewat kode; yang sudah ada diperbarui dari sel yang terisi.
 */
class WarehouseImport extends RowImporter
{
    public function title(): string
    {
        return 'Gudang';
    }

    public function columns(): array
    {
        return [
            'KODE GUDANG' => ['code', 'Ya', 'Kode unik, mis. UTAMA. Gudang yang sudah ada diperbarui.'],
            'NAMA GUDANG' => ['name', 'Gudang baru', 'Default = kode.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
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
        $row->requireText(['code' => 'KODE GUDANG']);
        $row->maxLength(['code' => ['KODE GUDANG', 20], 'name' => ['NAMA GUDANG', 80]]);
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $attributes = array_filter(['name' => $row->text('name') ?: null, 'is_active' => $active], fn ($value): bool => $value !== null);
        $warehouse = $this->findCi(Warehouse::query(), 'code', $code);

        if ($warehouse === null) {
            Warehouse::create(['code' => $code, 'name' => $code, ...$attributes]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($warehouse->fill($attributes));
    }
}
