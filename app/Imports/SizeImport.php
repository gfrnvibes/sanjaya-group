<?php

namespace App\Imports;

use App\Models\Size;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ukuran: dicocokkan lewat kode (S, M, L, ...); URUTAN menentukan urutan tampil.
 */
class SizeImport extends RowImporter
{
    public function title(): string
    {
        return 'Ukuran';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'UKURAN' => ['code', 'Ya', 'Kode ukuran maks. 15 karakter, mis. XL. Yang sudah ada diperbarui.'],
            'URUTAN' => ['sort_order', '-', 'Bilangan bulat; urutan tampil (kecil = lebih dulu). Default 0.'],
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
        $row->requireText(['code' => 'UKURAN']);
        $row->maxLength(['code' => ['UKURAN', 15]]);
        $order = $row->number('sort_order', 'URUTAN', min: 0, integer: true);
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $size = $this->findCi(Size::query(), 'code', $code);

        if ($size === null) {
            Size::create(['code' => $code, 'sort_order' => (int) ($order ?? 0)]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($size->fill(array_filter(['sort_order' => $order === null ? null : (int) $order], fn ($value): bool => $value !== null)));
    }

    protected function exportRows(): iterable
    {
        foreach (Size::query()->orderBy('sort_order')->orderBy('code')->lazy() as $size) {
            yield [$size->code, $size->sort_order];
        }
    }
}
