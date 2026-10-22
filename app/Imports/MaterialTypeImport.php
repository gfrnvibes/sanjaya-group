<?php

namespace App\Imports;

use App\Enums\MaterialKind;
use App\Models\MaterialType;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Jenis bahan (DRILL, KANCING, ...): dicocokkan lewat kode; yang sudah ada diperbarui dari sel yang terisi.
 */
class MaterialTypeImport extends RowImporter
{
    public function title(): string
    {
        return 'Jenis Bahan';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'KODE JENIS BAHAN' => ['code', 'Ya', 'Kode unik, mis. DRILL. Yang sudah ada diperbarui.'],
            'NAMA JENIS BAHAN' => ['name', 'Jenis baru', 'Default = kode.'],
            'KATEGORI BAHAN' => ['kind', 'Jenis baru', 'Kain / Aksesoris.'],
            'JUMLAH BAHAN' => ['material_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['JUMLAH BAHAN'];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return ['Bahan per warna (stok, satuan, harga) diimpor di menu Stok Bahan.'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE JENIS BAHAN']);
        $row->maxLength(['code' => ['KODE JENIS BAHAN', 30], 'name' => ['NAMA JENIS BAHAN', 80]]);
        $kind = $row->enum('kind', MaterialKind::class, 'KATEGORI BAHAN');
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $type = $this->findCi(MaterialType::query(), 'code', $code);

        if ($type === null) {
            $row->enum('kind', MaterialKind::class, 'KATEGORI BAHAN', required: true);
            $row->throwIfInvalid();

            MaterialType::create(['code' => $code, 'name' => $row->text('name') ?: $code, 'kind' => $kind]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($type->fill(array_filter(['name' => $row->text('name') ?: null, 'kind' => $kind])));
    }

    protected function exportRows(): iterable
    {
        foreach (MaterialType::query()->withCount('materials')->orderBy('code')->lazy() as $type) {
            yield [$type->code, $type->name, $type->kind->getLabel(), $type->materials_count];
        }
    }
}
