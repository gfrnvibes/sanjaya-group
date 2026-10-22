<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\Owner;
use App\Models\Reseller;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Reseller: dicocokkan lewat NAMA RESELLER; yang sudah ada diperbarui dari sel yang terisi.
 * Pemilik reseller yang sudah ada tidak dipindah lewat Excel (piutang & tagihan melekat ke pemilik).
 */
class ResellerImport extends RowImporter
{
    public function title(): string
    {
        return 'Reseller';
    }

    public function permission(): string
    {
        return 'kelola mitra';
    }

    public function columns(): array
    {
        return [
            'NAMA RESELLER' => ['name', 'Ya', 'Nama unik. Reseller yang sudah ada diperbarui.'],
            'KODE PEMILIK' => ['owner', 'Reseller baru', 'Kode pemilik (sheet Daftar Pemilik). Harus sudah ada di menu Pemilik.'],
            'SALDO AWAL' => ['opening_balance', '-', 'Piutang awal sebelum memakai sistem (Rp).'],
            'TGL SALDO AWAL' => ['opening_balance_date', '-', 'Tanggal saldo awal.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
            'NAMA PEMILIK' => ['owner_name', '-', 'Info dari sistem.'],
            'JUMLAH TOKO' => ['store_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['NAMA PEMILIK', 'JUMLAH TOKO'];
    }

    protected function requiredHeaders(): array
    {
        return ['name'];
    }

    protected function notes(): array
    {
        return ['Toko diimpor di menu Toko, atau sekaligus (pemilik, reseller, toko) lewat workbook Reseller & Toko di Pusat Import / Export.'];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Pemilik' => [[16, 30], [
                ['KODE PEMILIK', 'NAMA PEMILIK'],
                ...Owner::query()->orderBy('code')->get()->map(fn (Owner $owner): array => [$owner->code, $owner->name])->all(),
            ]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['name' => 'NAMA RESELLER']);
        $row->maxLength(['name' => ['NAMA RESELLER', 120]]);
        $balance = $row->number('opening_balance', 'SALDO AWAL');
        $balanceDate = $row->date('opening_balance_date', 'TGL SALDO AWAL');
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $owner = $row->filled('owner')
            ? ($this->findCi(Owner::query(), 'code', $row->text('owner'))
                ?? throw new BusinessRuleException("pemilik \"{$row->text('owner')}\" tidak ditemukan (impor dulu di menu Pemilik)"))
            : null;

        $attributes = array_filter([
            'opening_balance' => $balance,
            'opening_balance_date' => $balanceDate,
            'is_active' => $active,
        ], fn ($value): bool => $value !== null);
        $reseller = $this->findCi(Reseller::query(), 'name', $row->text('name'));

        if ($reseller === null) {
            if ($owner === null) {
                throw new BusinessRuleException('KODE PEMILIK wajib untuk reseller baru');
            }

            Reseller::create(['name' => $row->text('name'), 'owner_id' => $owner->id, ...$attributes]);
            $this->bump('created');

            return;
        }

        if ($owner !== null && $owner->id !== $reseller->owner_id) {
            throw new BusinessRuleException("reseller {$reseller->name} milik pemilik lain; pindah pemilik tidak bisa lewat Excel");
        }

        $reseller->fill($attributes);

        if ($balance !== null && round((float) $reseller->getOriginal('opening_balance'), 2) === round($balance, 2)) {
            $reseller->syncOriginalAttribute('opening_balance');
        }

        if ($balanceDate !== null && $reseller->getOriginal('opening_balance_date')?->toDateString() === $balanceDate) {
            $reseller->syncOriginalAttribute('opening_balance_date');
        }

        $this->saveIfDirty($reseller);
    }

    protected function exportRows(): iterable
    {
        foreach (Reseller::query()->with('owner')->withCount('stores')->orderBy('name')->lazy() as $reseller) {
            yield [
                $reseller->name, $reseller->owner?->code, (float) $reseller->opening_balance, $reseller->opening_balance_date?->toDateString(),
                $reseller->is_active ? 'Ya' : 'Tidak', $reseller->owner?->name, $reseller->stores_count,
            ];
        }
    }
}
