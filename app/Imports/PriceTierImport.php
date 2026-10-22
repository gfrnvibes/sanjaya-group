<?php

namespace App\Imports;

use App\Models\PriceTier;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tier harga reseller: dicocokkan lewat KODE TIER. PENYESUAIAN % = tambahan dari harga dasar (30 = +30%).
 */
class PriceTierImport extends RowImporter
{
    public function title(): string
    {
        return 'Tier Harga';
    }

    public function permission(): string
    {
        return 'kelola mitra';
    }

    public function columns(): array
    {
        return [
            'KODE TIER' => ['code', 'Ya', 'Kode unik. Tier yang sudah ada diperbarui.'],
            'NAMA TIER' => ['name', 'Tier baru', 'Default = kode.'],
            'PENYESUAIAN %' => ['adjustment', 'Tier baru', 'Tambahan dari harga dasar, mis. 30 atau 30%. Boleh negatif (diskon).'],
            'JUMLAH PEMILIK' => ['owner_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['JUMLAH PEMILIK'];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return ['Mengubah PENYESUAIAN % berlaku untuk semua pemilik dengan tier ini, termasuk pesanan baru setelah impor.'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE TIER']);
        $row->maxLength(['code' => ['KODE TIER', 20], 'name' => ['NAMA TIER', 60]]);
        $adjustment = $row->filled('adjustment') ? $this->adjustment($row) : null;
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $attributes = array_filter(['name' => $row->text('name') ?: null, 'adjustment_pct' => $adjustment], fn ($value): bool => $value !== null);
        $tier = $this->findCi(PriceTier::query(), 'code', $code);

        if ($tier === null) {
            PriceTier::create(['code' => $code, 'name' => $code, 'adjustment_pct' => 0, ...$attributes]);
            $this->bump('created');

            return;
        }

        $tier->fill($attributes);

        if ($tier->isDirty('adjustment_pct') && round((float) $tier->getOriginal('adjustment_pct'), 4) === round((float) $adjustment, 4)) {
            $tier->syncOriginalAttribute('adjustment_pct');
        }

        $this->saveIfDirty($tier);
    }

    protected function exportRows(): iterable
    {
        foreach (PriceTier::query()->withCount('ownerTierHistories')->orderBy('code')->lazy() as $tier) {
            yield [$tier->code, $tier->name, round((float) $tier->adjustment_pct * 100, 2), $tier->owner_tier_histories_count];
        }
    }

    /**
     * Persen dengan tanda: "30", "30%", "-5" (tidak memakai aturan "angka <= 1 = pecahan").
     */
    private function adjustment(SpreadsheetRow $row): ?float
    {
        $value = $row->number('adjustment', 'PENYESUAIAN %');

        return $value === null ? null : round($value / 100, 4);
    }
}
