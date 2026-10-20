<?php

namespace App\Imports;

use App\Models\Tailor;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Penjahit: dicocokkan lewat nama; yang sudah ada diperbarui dari sel yang terisi.
 */
class TailorImport extends RowImporter
{
    public function title(): string
    {
        return 'Penjahit';
    }

    public function columns(): array
    {
        return [
            'NAMA PENJAHIT' => ['name', 'Ya', 'Penjahit yang sudah ada (nama sama) diperbarui.'],
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
        $row->requireText(['name' => 'NAMA PENJAHIT']);
        $row->maxLength(['name' => ['NAMA PENJAHIT', 120], 'phone' => ['NO HP', 30]]);
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $attributes = array_filter(['phone' => $row->text('phone') ?: null, 'is_active' => $active], fn ($value): bool => $value !== null);
        $tailor = $this->findCi(Tailor::query(), 'name', $row->text('name'));

        if ($tailor === null) {
            Tailor::create(['name' => $row->text('name'), ...$attributes]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($tailor->fill($attributes));
    }
}
