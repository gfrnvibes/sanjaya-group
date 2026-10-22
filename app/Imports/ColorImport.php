<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\Color;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Warna (dipakai varian & bahan): dicocokkan lewat KODE WARNA; yang sudah ada diganti namanya.
 */
class ColorImport extends RowImporter
{
    public function title(): string
    {
        return 'Warna';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'KODE WARNA' => ['code', 'Ya', 'Kode unik maks. 10 karakter, mis. HT. Warna yang sudah ada diperbarui.'],
            'NAMA WARNA' => ['name', 'Warna baru', 'Mis. Hitam. Nama harus unik.'],
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
        $row->requireText(['code' => 'KODE WARNA']);
        $row->maxLength(['code' => ['KODE WARNA', 10], 'name' => ['NAMA WARNA', 60]]);
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $name = $row->text('name');
        $color = $this->findCi(Color::query(), 'code', $code);
        $sameName = $name === '' ? null : $this->findCi(Color::query(), 'name', $name);

        if ($sameName !== null && $sameName->id !== $color?->id) {
            throw new BusinessRuleException("NAMA WARNA \"{$name}\" sudah dipakai kode {$sameName->code}");
        }

        if ($color === null) {
            Color::create(['code' => $code, 'name' => $name ?: $code]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($color->fill(array_filter(['name' => $name ?: null])));
    }

    protected function exportRows(): iterable
    {
        foreach (Color::query()->orderBy('code')->lazy() as $color) {
            yield [$color->code, $color->name];
        }
    }
}
