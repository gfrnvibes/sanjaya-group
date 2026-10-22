<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\Owner;
use App\Models\OwnerTierHistory;
use App\Models\PriceTier;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pemilik toko: dicocokkan lewat KODE PEMILIK. TIER diisi = ditambahkan ke riwayat tier mulai TIER BERLAKU MULAI.
 */
class OwnerImport extends RowImporter
{
    public function title(): string
    {
        return 'Pemilik';
    }

    public function permission(): string
    {
        return 'kelola mitra';
    }

    public function columns(): array
    {
        return [
            'KODE PEMILIK' => ['code', 'Ya', 'Kode unik. Pemilik yang sudah ada diperbarui.'],
            'NAMA PEMILIK' => ['name', 'Pemilik baru', 'Default = kode.'],
            'NO HP' => ['phone', '-', 'Format kolom sebagai teks agar angka 0 di depan tidak hilang.'],
            'TIER' => ['tier', '-', 'Kode/nama tier harga (sheet Daftar Tier). Harus sudah ada di menu Tier Harga.'],
            'TIER BERLAKU MULAI' => ['tier_from', 'Bila TIER diisi', 'Tanggal tier mulai berlaku. Kosong = hari ini.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
            'JUMLAH RESELLER' => ['reseller_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['JUMLAH RESELLER'];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return [
            'Tier yang sama dengan tier berlaku dilewati; tier berbeda ditambahkan ke riwayat (harga reseller pesanan setelah tanggal itu ikut tier baru).',
            'Reseller & toko diimpor di menunya masing-masing, atau sekaligus lewat workbook Reseller & Toko di Pusat Import / Export.',
        ];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Tier' => [[14, 30, 14], [
                ['KODE TIER', 'NAMA', 'PENYESUAIAN %'],
                ...PriceTier::query()->orderBy('code')->get()->map(fn (PriceTier $tier): array => [$tier->code, $tier->name, round((float) $tier->adjustment_pct * 100, 2)])->all(),
            ]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE PEMILIK']);
        $row->maxLength(['code' => ['KODE PEMILIK', 30], 'name' => ['NAMA PEMILIK', 120], 'phone' => ['NO HP', 30]]);
        $active = $row->bool('is_active');
        $tierFrom = $row->date('tier_from', 'TIER BERLAKU MULAI');
        $row->throwIfInvalid();

        $tier = null;

        if ($row->filled('tier')) {
            $tier = $this->findCi(PriceTier::query(), 'code', $row->text('tier')) ?? $this->findCi(PriceTier::query(), 'name', $row->text('tier'))
                ?? throw new BusinessRuleException("tier \"{$row->text('tier')}\" tidak ditemukan (buat dulu di menu Tier Harga)");
        }

        $code = Str::upper($row->text('code'));
        $attributes = array_filter(['name' => $row->text('name') ?: null, 'phone' => $row->text('phone') ?: null, 'is_active' => $active], fn ($value): bool => $value !== null);
        $owner = $this->findCi(Owner::query(), 'code', $code);

        if ($owner === null) {
            $owner = Owner::create(['code' => $code, 'name' => $code, ...$attributes]);
            $this->bump('created');
        } else {
            $this->saveIfDirty($owner->fill($attributes));
        }

        if ($tier === null) {
            return;
        }

        $from = $tierFrom ?? today()->toDateString();
        $current = $owner->tierHistories()->whereDate('effective_from', '<=', $from)->orderByDesc('effective_from')->first();

        if ($current?->price_tier_id === $tier->id) {
            return;
        }

        OwnerTierHistory::query()->updateOrCreate(
            ['owner_id' => $owner->id, 'effective_from' => $from],
            ['price_tier_id' => $tier->id, 'note' => self::NOTE],
        );
        $this->bump('created', 'Riwayat Tier');
        $this->warn("tier pemilik {$owner->code} menjadi {$tier->code} mulai {$from}.");
    }

    protected function exportRows(): iterable
    {
        foreach (Owner::query()->withCount('resellers')->with(['tierHistories' => fn ($query) => $query->whereDate('effective_from', '<=', today())->orderByDesc('effective_from'), 'tierHistories.priceTier'])->orderBy('code')->lazy() as $owner) {
            $tier = $owner->tierHistories->first();

            yield [
                $owner->code, $owner->name, $owner->phone, $tier?->priceTier?->code, $tier?->effective_from?->toDateString(),
                $owner->is_active ? 'Ya' : 'Tidak', $owner->resellers_count,
            ];
        }
    }
}
