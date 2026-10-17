<?php

namespace App\Services;

use App\Enums\ChannelKind;
use App\Exceptions\BusinessRuleException;
use App\Models\Channel;
use App\Models\Owner;
use App\Models\PriceTier;
use App\Models\Reseller;
use App\Models\Store;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Impor 1 file untuk grup Reseller & Toko: 1 baris = 1 toko.
 * Pemilik (kode), reseller (nama), dan channel (kode) yang sudah ada dipakai ulang tanpa diubah;
 * yang belum ada dibuat. Riwayat tier pemilik ditambahkan bila kolom TIER diisi.
 * Tiap baris disimpan terpisah: baris valid tersimpan, baris error dilaporkan.
 */
class StoreImporter
{
    /** LABEL HEADER => kunci; urutan = urutan kolom template. */
    public const COLUMNS = [
        'KODE PEMILIK' => 'owner_code',
        'NAMA PEMILIK' => 'owner_name',
        'NO HP PEMILIK' => 'owner_phone',
        'TIER' => 'tier',
        'TIER BERLAKU MULAI' => 'tier_from',
        'NAMA RESELLER' => 'reseller',
        'SALDO AWAL' => 'opening_balance',
        'TGL SALDO AWAL' => 'opening_balance_date',
        'NAMA TOKO' => 'store',
        'KODE CHANNEL' => 'channel_code',
        'NAMA CHANNEL' => 'channel_name',
        'JENIS CHANNEL' => 'channel_kind',
        'ID TOKO MARKETPLACE' => 'external_ref',
        'AKTIF' => 'is_active',
    ];

    private const REQUIRED = ['owner_code', 'reseller', 'store', 'channel_code'];

    /** @var Collection<int, PriceTier>|null */
    private ?Collection $tiers = null;

    /**
     * @return array{rows: int, created: array{owners: int, resellers: int, channels: int, tiers: int, stores: int}, errors: list<string>}
     */
    public function import(string $path, string $fileName): array
    {
        $created = ['owners' => 0, 'resellers' => 0, 'channels' => 0, 'tiers' => 0, 'stores' => 0];
        $errors = [];

        try {
            $lines = Spreadsheet::read($path, $fileName, self::COLUMNS, self::REQUIRED,
                'Header tidak ditemukan. Pastikan ada kolom KODE PEMILIK, NAMA RESELLER, NAMA TOKO, dan KODE CHANNEL (gunakan template).');
        } catch (BusinessRuleException $exception) {
            return ['rows' => 0, 'created' => $created, 'errors' => [$exception->getMessage()]];
        }

        foreach ($lines as $line) {
            try {
                $row = $this->validate($line['values']);
                $rowCreated = DB::transaction(fn (): array => $this->storeRow($row));

                foreach ($rowCreated as $key => $count) {
                    $created[$key] += $count;
                }
            } catch (BusinessRuleException $exception) {
                $errors[] = "Baris {$line['line']}: {$exception->getMessage()}";
            } catch (QueryException $exception) {
                $errors[] = "Baris {$line['line']}: gagal disimpan (".Str::limit(strtok($exception->getMessage(), "\n") ?: 'kesalahan database', 160).').';
            }
        }

        if ($lines === []) {
            $errors[] = 'Tidak ada baris data yang bisa diimpor.';
        }

        return ['rows' => count($lines), 'created' => $created, 'errors' => $errors];
    }

    /**
     * Template kosong + petunjuk + daftar referensi. Mengembalikan path file sementara.
     */
    public function template(): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Input Toko');
        foreach ([14, 22, 16, 10, 18, 26, 14, 16, 30, 14, 16, 16, 20, 8] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Spreadsheet::headerRow(array_fill_keys(array_keys(self::COLUMNS), true)));

        Spreadsheet::addSheet($writer, 'Petunjuk', [24, 12, 100], [
            ['KOLOM', 'WAJIB', 'KETERANGAN'],
            ['KODE PEMILIK', 'Ya', 'Kode unik pemilik. Bila sudah ada, pemilik dipakai ulang (data pemilik tidak diubah).'],
            ['NAMA PEMILIK', 'Pemilik baru', 'Nama pemilik. Wajib bila KODE PEMILIK belum terdaftar.'],
            ['NO HP PEMILIK', '-', 'Hanya dipakai saat pemilik baru dibuat.'],
            ['TIER', '-', 'Kode/nama tier harga (lihat sheet Daftar Tier). Ditambahkan ke riwayat tier pemilik.'],
            ['TIER BERLAKU MULAI', 'Bila TIER diisi', 'Tanggal tier mulai berlaku (YYYY-MM-DD / DD/MM/YYYY).'],
            ['NAMA RESELLER', 'Ya', 'Nama reseller/brand (unik). Bila sudah ada, harus milik pemilik yang sama.'],
            ['SALDO AWAL', '-', 'Saldo tagihan awal reseller. Hanya dipakai saat reseller baru dibuat.'],
            ['TGL SALDO AWAL', '-', 'Tanggal saldo awal.'],
            ['NAMA TOKO', 'Ya', 'Nama toko (unik), mis. "ARR SANJAYA Shopee". Toko yang sudah ada akan ditolak.'],
            ['KODE CHANNEL', 'Ya', 'Kode channel, mis. SHOPEE / TIKTOK / WA. Bila belum ada, channel baru dibuat.'],
            ['NAMA CHANNEL', '-', 'Nama channel baru (default = kode).'],
            ['JENIS CHANNEL', 'Channel baru', 'marketplace / website / chat / offline / other.'],
            ['ID TOKO MARKETPLACE', '-', 'ID toko di BigSeller / marketplace.'],
            ['AKTIF', '-', 'Ya / Tidak. Default Ya.'],
            ['', '', ''],
            ['Catatan', '', '1 baris = 1 toko. Pemilik & reseller yang sama cukup ditulis ulang di tiap baris tokonya.'],
            ['', '', 'Baris yang valid tetap disimpan; baris error dilaporkan setelah impor.'],
            ['Contoh', '', 'ARF | ARIFIN | 08123456789 | T1 | 2026-10-01 | ARR SANJAYA | 0 | | ARR SANJAYA Shopee | SHOPEE | Shopee | marketplace | | Ya'],
        ]);

        Spreadsheet::addSheet($writer, 'Daftar Pemilik', [16, 30, 40], [
            ['KODE PEMILIK', 'NAMA PEMILIK', 'RESELLER'],
            ...Owner::query()->with('resellers')->orderBy('code')->get()
                ->map(fn (Owner $owner): array => [$owner->code, $owner->name, $owner->resellers->pluck('name')->implode(', ')])
                ->all(),
        ]);

        Spreadsheet::addSheet($writer, 'Daftar Channel', [16, 24, 16], [
            ['KODE CHANNEL', 'NAMA CHANNEL', 'JENIS CHANNEL'],
            ...Channel::query()->orderBy('code')->get()
                ->map(fn (Channel $channel): array => [$channel->code, $channel->name, $channel->kind->value])
                ->all(),
        ]);

        Spreadsheet::addSheet($writer, 'Daftar Tier', [14, 24, 16], [
            ['KODE TIER', 'NAMA TIER', 'PENYESUAIAN %'],
            ...PriceTier::query()->orderBy('code')->get()
                ->map(fn (PriceTier $tier): array => [$tier->code, $tier->name, round((float) $tier->adjustment_pct * 100, 2)])
                ->all(),
        ]);

        $writer->close();

        return $path;
    }

    /**
     * Validasi format satu baris (tanpa menyentuh database kecuali master tier).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $text = fn (string $key): string => Spreadsheet::text($input[$key] ?? null);
        $errors = [];

        $row = [
            'owner_code' => $text('owner_code'),
            'owner_name' => $text('owner_name'),
            'owner_phone' => $text('owner_phone') ?: null,
            'reseller' => $text('reseller'),
            'store' => $text('store'),
            'channel_code' => Str::upper($text('channel_code')),
            'channel_name' => $text('channel_name'),
            'external_ref' => $text('external_ref') ?: null,
            'tier' => null,
            'tier_from' => null,
            'opening_balance' => 0,
            'opening_balance_date' => null,
            'channel_kind' => null,
            'is_active' => true,
        ];

        foreach (['owner_code' => 'KODE PEMILIK', 'reseller' => 'NAMA RESELLER', 'store' => 'NAMA TOKO', 'channel_code' => 'KODE CHANNEL'] as $key => $label) {
            if ($row[$key] === '') {
                $errors[] = "{$label} kosong";
            }
        }

        foreach ([
            'owner_code' => ['KODE PEMILIK', 30], 'owner_name' => ['NAMA PEMILIK', 120], 'owner_phone' => ['NO HP PEMILIK', 30],
            'reseller' => ['NAMA RESELLER', 120], 'store' => ['NAMA TOKO', 120], 'channel_code' => ['KODE CHANNEL', 20],
            'channel_name' => ['NAMA CHANNEL', 60], 'external_ref' => ['ID TOKO MARKETPLACE', 120],
        ] as $key => [$label, $max]) {
            if (mb_strlen((string) $row[$key]) > $max) {
                $errors[] = "{$label} maksimal {$max} karakter";
            }
        }

        if ($text('tier') !== '') {
            $row['tier'] = $this->findTier($text('tier'));
            $row['tier_from'] = Spreadsheet::parseDate($input['tier_from'] ?? null);

            if ($row['tier'] === null) {
                $errors[] = "tier \"{$text('tier')}\" tidak ditemukan";
            }

            if ($row['tier_from'] === null) {
                $errors[] = 'TIER BERLAKU MULAI kosong atau formatnya tidak dikenali';
            }
        }

        if ($text('opening_balance') !== '') {
            $row['opening_balance'] = Spreadsheet::parseNumber($input['opening_balance']);

            if ($row['opening_balance'] === null) {
                $errors[] = 'SALDO AWAL harus angka';
            }
        }

        if ($text('opening_balance_date') !== '') {
            $row['opening_balance_date'] = Spreadsheet::parseDate($input['opening_balance_date']);

            if ($row['opening_balance_date'] === null) {
                $errors[] = 'TGL SALDO AWAL formatnya tidak dikenali';
            }
        }

        if ($text('channel_kind') !== '') {
            $row['channel_kind'] = $this->parseChannelKind($text('channel_kind'));

            if ($row['channel_kind'] === null) {
                $errors[] = 'JENIS CHANNEL harus marketplace / website / chat / offline / other';
            }
        }

        if ($text('is_active') !== '') {
            $row['is_active'] = Spreadsheet::parseBool($input['is_active']);

            if ($row['is_active'] === null) {
                $errors[] = 'AKTIF harus Ya / Tidak';
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException(implode('; ', $errors).'.');
        }

        return $row;
    }

    /**
     * Simpan satu baris (dipanggil di dalam transaksi).
     *
     * @param  array<string, mixed>  $row
     * @return array{owners: int, resellers: int, channels: int, tiers: int, stores: int}
     */
    private function storeRow(array $row): array
    {
        $created = ['owners' => 0, 'resellers' => 0, 'channels' => 0, 'tiers' => 0, 'stores' => 0];

        if ($this->findByName(Store::withTrashed(), 'name', $row['store'])) {
            throw new BusinessRuleException("toko \"{$row['store']}\" sudah ada.");
        }

        $owner = $this->findByName(Owner::withTrashed(), 'code', $row['owner_code']);
        if ($owner?->trashed()) {
            throw new BusinessRuleException("pemilik {$row['owner_code']} sudah dihapus.");
        }
        if ($owner === null) {
            if ($row['owner_name'] === '') {
                throw new BusinessRuleException("pemilik {$row['owner_code']} belum terdaftar, NAMA PEMILIK wajib diisi.");
            }

            $owner = Owner::create(['code' => $row['owner_code'], 'name' => $row['owner_name'], 'phone' => $row['owner_phone']]);
            $created['owners']++;
        }

        if ($row['tier'] !== null) {
            $history = $owner->tierHistories()->whereDate('effective_from', $row['tier_from'])->first();

            if ($history === null) {
                $owner->tierHistories()->create(['price_tier_id' => $row['tier']->id, 'effective_from' => $row['tier_from'], 'note' => 'Impor toko']);
                $created['tiers']++;
            } elseif ($history->price_tier_id !== $row['tier']->id) {
                throw new BusinessRuleException("pemilik {$owner->code} sudah punya tier lain yang berlaku mulai {$row['tier_from']}.");
            }
        }

        $channel = $this->findByName(Channel::query(), 'code', $row['channel_code']);
        if ($channel === null) {
            if ($row['channel_kind'] === null) {
                throw new BusinessRuleException("channel {$row['channel_code']} belum terdaftar, JENIS CHANNEL wajib diisi.");
            }

            $channel = Channel::create([
                'code' => $row['channel_code'],
                'name' => $row['channel_name'] ?: $row['channel_code'],
                'kind' => $row['channel_kind'],
            ]);
            $created['channels']++;
        }

        $reseller = $this->findByName(Reseller::withTrashed(), 'name', $row['reseller']);
        if ($reseller?->trashed()) {
            throw new BusinessRuleException("reseller \"{$row['reseller']}\" sudah dihapus.");
        }
        if ($reseller !== null && $reseller->owner_id !== $owner->id) {
            throw new BusinessRuleException("reseller \"{$row['reseller']}\" sudah terdaftar atas pemilik lain.");
        }
        if ($reseller === null) {
            $reseller = Reseller::create([
                'owner_id' => $owner->id,
                'name' => $row['reseller'],
                'opening_balance' => $row['opening_balance'],
                'opening_balance_date' => $row['opening_balance_date'],
            ]);
            $created['resellers']++;
        }

        Store::create([
            'reseller_id' => $reseller->id,
            'channel_id' => $channel->id,
            'name' => $row['store'],
            'external_ref' => $row['external_ref'],
            'is_active' => $row['is_active'],
        ]);
        $created['stores']++;

        return $created;
    }

    /**
     * Cari tanpa membedakan huruf besar/kecil.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function findByName(Builder $query, string $column, string $value): ?Model
    {
        return $query->whereRaw("lower({$column}) = ?", [Str::lower($value)])->first();
    }

    private function findTier(string $value): ?PriceTier
    {
        $this->tiers ??= PriceTier::query()->get();
        $value = Str::lower($value);

        return $this->tiers->first(fn (PriceTier $tier): bool => Str::lower($tier->code) === $value || Str::lower($tier->name) === $value);
    }

    private function parseChannelKind(string $value): ?ChannelKind
    {
        $value = Str::lower($value);

        return ChannelKind::tryFrom($value)
            ?? collect(ChannelKind::cases())->first(fn (ChannelKind $kind): bool => Str::lower($kind->getLabel()) === $value)
            ?? ($value === 'wa' ? ChannelKind::Chat : null);
    }
}
