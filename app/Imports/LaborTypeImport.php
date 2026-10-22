<?php

namespace App\Imports;

use App\Models\LaborType;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Jenis ongkos (POTONG, JAHIT, ...): dicocokkan lewat kode. Tarif per pcs diatur per model di Resep (BOM).
 */
class LaborTypeImport extends RowImporter
{
    public function title(): string
    {
        return 'Jenis Ongkos';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'KODE ONGKOS' => ['code', 'Ya', 'Kode unik, mis. JAHIT. Yang sudah ada diperbarui.'],
            'NAMA ONGKOS' => ['name', 'Jenis baru', 'Default = kode.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return ['Tarif ongkos per pcs diisi per model di Resep (BOM); upah jahit SPK dihitung dari tarif tersebut.'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE ONGKOS']);
        $row->maxLength(['code' => ['KODE ONGKOS', 20], 'name' => ['NAMA ONGKOS', 60]]);
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $type = $this->findCi(LaborType::query(), 'code', $code);

        if ($type === null) {
            LaborType::create(['code' => $code, 'name' => $row->text('name') ?: $code]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($type->fill(array_filter(['name' => $row->text('name') ?: null])));
    }

    protected function exportRows(): iterable
    {
        foreach (LaborType::query()->orderBy('code')->lazy() as $type) {
            yield [$type->code, $type->name];
        }
    }
}
