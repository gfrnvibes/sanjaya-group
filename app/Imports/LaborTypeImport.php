<?php

namespace App\Imports;

use App\Enums\WageType;
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
            'DIBAYAR SEBAGAI' => ['wage_type', '-', 'Upah Jahit / Upah Potong / Biaya (dibayar lewat Biaya Operasional). Kosong untuk jenis baru = ditebak dari kode/nama.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return ['Tarif ongkos per pcs diisi per model di Resep (BOM). Upah jahit & upah potong SPK dihitung dari ongkos yang DIBAYAR SEBAGAI Upah Jahit / Upah Potong.'];
    }

    /**
     * DIBAYAR SEBAGAI: false = kosong (tidak diubah / ditebak), null = Biaya Operasional, atau jenis upah.
     */
    private function wageType(SpreadsheetRow $row): WageType|false|null
    {
        if (! $row->filled('wage_type')) {
            return false;
        }

        $text = Str::lower($row->text('wage_type'));

        if (in_array($text, ['biaya', 'biaya operasional', 'tidak', '-'], true)) {
            return null;
        }

        return $row->enum('wage_type', WageType::class, 'DIBAYAR SEBAGAI', ['jahit' => WageType::Jahit, 'potong' => WageType::Potong]);
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE ONGKOS']);
        $row->maxLength(['code' => ['KODE ONGKOS', 20], 'name' => ['NAMA ONGKOS', 60]]);
        $wage = $this->wageType($row);
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $type = $this->findCi(LaborType::query(), 'code', $code);

        if ($type === null) {
            LaborType::create([
                'code' => $code,
                'name' => $row->text('name') ?: $code,
                'wage_type' => $wage === false ? LaborType::guessWageType($code, $row->text('name')) : $wage,
            ]);
            $this->bump('created');

            return;
        }

        $type->fill(array_filter(['name' => $row->text('name') ?: null]));

        if ($wage !== false) {
            $type->wage_type = $wage;
        }

        $this->saveIfDirty($type);
    }

    protected function exportRows(): iterable
    {
        foreach (LaborType::query()->orderBy('code')->lazy() as $type) {
            yield [$type->code, $type->name, $type->wage_type?->getLabel() ?? 'Biaya'];
        }
    }
}
