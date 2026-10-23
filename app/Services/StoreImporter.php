<?php

namespace App\Services;

use App\Enums\ChannelKind;
use App\Exceptions\BusinessRuleException;
use App\Imports\ImportIssueLog;
use App\Models\Channel;
use App\Models\Owner;
use App\Models\OwnerTierHistory;
use App\Models\PriceTier;
use App\Models\Reseller;
use App\Models\Store;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetCells;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Workbook RESELLER & TOKO (1 baris = 1 toko): export -> edit di Excel -> import ulang.
 *
 * - Baris dengan ID TOKO / nama toko yang sudah ada = DIPERBARUI dari sel yang terisi (ganti nama lewat ID).
 * - Baris baru (ID kosong) = dibuat; pemilik, reseller, channel, tier yang belum ada ikut dibuat.
 * - Pindah toko ke reseller lain / reseller ke pemilik lain TIDAK lewat Excel (memengaruhi tagihan).
 * - Baris yang dihapus di Excel tidak menghapus data; nonaktifkan dengan AKTIF = Tidak.
 * - Bila data diubah di sistem setelah file di-export (kolom DIUBAH TERAKHIR), isi file tetap dipakai + peringatan.
 */
class StoreImporter
{
    /** Kolom input (header merah); urutan = urutan kolom file. */
    /** Kategori di "Import Perlu Dicek". */
    public const ISSUE_KEY = 'stores';

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

    /** Kolom sistem (header hijau, jangan diubah): kunci untuk ganti nama & deteksi perubahan. */
    public const SYSTEM_COLUMNS = [
        'ID TOKO' => 'store_id',
        'ID RESELLER' => 'reseller_id',
        'DIUBAH TERAKHIR' => 'exported_at',
    ];

    private const REQUIRED = ['owner_code', 'reseller', 'store', 'channel_code'];

    /** Tier yang belum terdaftar dibuat dengan nama & penyesuaian ini (0.30 = harga dasar +30%). */
    public const NEW_TIER_NAME = 'Belum diatur';

    public const NEW_TIER_ADJUSTMENT = 0.30;

    private const WIDTHS = [14, 22, 16, 10, 18, 26, 14, 16, 30, 14, 16, 16, 20, 8, 10, 12, 19];

    /** @var list<string> peringatan baris yang sedang diproses */
    private array $pendingWarnings = [];

    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $pending = [];

    /**
     * @return array{rows: int, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    public function import(string $path, string $fileName): array
    {
        try {
            $lines = Spreadsheet::read($path, $fileName, [...self::COLUMNS, ...self::SYSTEM_COLUMNS, ImportIssueLog::FAILURE_ID_COLUMN => 'failure_id'], self::REQUIRED,
                'Header tidak ditemukan. Pastikan ada kolom KODE PEMILIK, NAMA RESELLER, NAMA TOKO, dan KODE CHANNEL (gunakan template/export).');
        } catch (BusinessRuleException $exception) {
            return ['rows' => 0, 'stats' => [], 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        return $this->process($lines, $fileName);
    }

    /**
     * Coba ulang baris dari "Import Perlu Dicek" (nilai per LABEL HEADER).
     *
     * @param  list<array{line: int, values: array<string, mixed>, failure_id: ?int}>  $rows
     * @return array{rows: int, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    public function importValues(array $rows, string $fileName = 'Perbaikan langsung'): array
    {
        $map = [...self::COLUMNS, ...self::SYSTEM_COLUMNS];

        return $this->process(array_map(fn (array $row): array => [
            'line' => $row['line'],
            'values' => [
                ...collect($map)->mapWithKeys(fn (string $key, string $label): array => [$key => $row['values'][$label] ?? null])->all(),
                'failure_id' => $row['failure_id'] ?? null,
            ],
        ], $rows), $fileName);
    }

    /**
     * @param  list<array{line: int, values: array<string, mixed>}>  $lines
     * @return array{rows: int, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    private function process(array $lines, string $fileName): array
    {
        $stats = [];
        $errors = [];
        $warnings = [];
        $issues = new ImportIssueLog(self::ISSUE_KEY, $fileName);
        $labels = [...self::COLUMNS, ...self::SYSTEM_COLUMNS];

        foreach ($lines as $line) {
            $this->pendingWarnings = [];
            $this->pending = [];
            $failureId = ImportIssueLog::failureId($line['values']['failure_id'] ?? null);
            $errorCount = count($errors);

            try {
                $row = $this->validate($line['values']);
                DB::transaction(fn () => $this->storeRow($row));
                $issues->fixed($failureId);

                foreach ($this->pending as $label => $counts) {
                    $stats[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

                    foreach ($counts as $kind => $count) {
                        $stats[$label][$kind] += $count;
                    }
                }

                foreach ($this->pendingWarnings as $warning) {
                    $warnings[] = "Baris {$line['line']}: {$warning}";
                }
            } catch (BusinessRuleException $exception) {
                $errors[] = "Baris {$line['line']}: ".rtrim($exception->getMessage(), '.').'.';
            } catch (QueryException $exception) {
                report($exception);
                $errors[] = "Baris {$line['line']}: gagal disimpan. Periksa data atau hubungi administrator.";
            }

            if (count($errors) > $errorCount) {
                $issues->fail(
                    $line['line'],
                    collect($labels)->map(fn (string $key) => Spreadsheet::text($line['values'][$key] ?? null))->all(),
                    end($errors),
                    retryOf: $failureId,
                );
            }
        }

        if ($lines === []) {
            $errors[] = 'Tidak ada baris data yang bisa diimpor.';
        }

        $issues->save();

        return ['rows' => count($lines), 'stats' => $stats, 'errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Template kosong (+ petunjuk & daftar referensi). Mengembalikan path file sementara.
     */
    public function template(): string
    {
        return $this->workbook(withData: false);
    }

    /**
     * Export semua toko dengan layout yang sama dengan template: diedit lalu diimpor ulang.
     */
    public function export(): string
    {
        return $this->workbook(withData: true);
    }

    private function workbook(bool $withData): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Toko');
        foreach (self::WIDTHS as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Spreadsheet::headerRow([
            ...array_fill_keys(array_keys(self::COLUMNS), true),
            ...array_fill_keys(array_keys(self::SYSTEM_COLUMNS), false),
        ]));

        if ($withData) {
            foreach ($this->exportRows() as $values) {
                $writer->addRow(SpreadsheetCells::row($values));
            }
        }

        Spreadsheet::addSheet($writer, 'Petunjuk', [24, 14, 100], [
            ['KOLOM', 'WAJIB', 'KETERANGAN'],
            ['KODE PEMILIK', 'Ya', 'Kode unik pemilik (kunci pemilik). Pemilik yang sudah ada: nama & no HP diperbarui dari sel yang terisi.'],
            ['NAMA PEMILIK', 'Pemilik baru', 'Wajib bila KODE PEMILIK belum terdaftar.'],
            ['NO HP PEMILIK', '-', 'Format kolom sebagai teks agar angka 0 di depan tidak hilang.'],
            ['TIER', '-', 'Kode/nama tier harga (sheet Daftar Tier). Ditambahkan ke riwayat tier pemilik. Tier baru dibuat otomatis: nama "Belum diatur", +30% dari harga dasar.'],
            ['TIER BERLAKU MULAI', 'Bila TIER diisi', 'Tanggal tier mulai berlaku (YYYY-MM-DD / DD/MM/YYYY).'],
            ['NAMA RESELLER', 'Ya', 'Nama reseller/brand (unik). Ganti nama: ubah nama, biarkan ID RESELLER.'],
            ['SALDO AWAL, TGL SALDO AWAL', '-', 'Saldo tagihan awal reseller. Mengubah saldo awal ikut mengubah piutang reseller.'],
            ['NAMA TOKO', 'Ya', 'Nama toko (unik). Ganti nama: ubah nama, biarkan ID TOKO.'],
            ['KODE CHANNEL', 'Ya', 'Kode channel, mis. SHOPEE / TIKTOK / WA. Channel baru dibuat otomatis (butuh JENIS CHANNEL).'],
            ['NAMA CHANNEL, JENIS CHANNEL', 'Channel baru', 'Jenis: marketplace / website / chat / offline / other.'],
            ['ID TOKO MARKETPLACE', '-', 'ID toko di BigSeller / marketplace.'],
            ['AKTIF', '-', 'Ya / Tidak. Toko tidak dihapus lewat Excel; isi Tidak untuk menonaktifkan.'],
            ['ID TOKO, ID RESELLER', 'Jangan diubah', 'Kolom hijau dari sistem. Kosong = data baru.'],
            ['DIUBAH TERAKHIR', 'Jangan diubah', 'Bila data diubah di sistem setelah file ini di-export, isi file tetap dipakai dan muncul peringatan.'],
            ['', '', ''],
            ['Alur', '', 'Export -> edit di Excel -> Import. Baris valid disimpan, baris error dilaporkan. Menghapus baris di Excel TIDAK menghapus data.'],
            ['', '', 'Pindah toko ke reseller lain atau reseller ke pemilik lain tidak bisa lewat Excel (memengaruhi tagihan) — ubah lewat menu Toko/Reseller.'],
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
     * @return list<list<mixed>>
     */
    private function exportRows(): array
    {
        $tiers = OwnerTierHistory::query()
            ->with('priceTier')
            ->whereDate('effective_from', '<=', today())
            ->orderByDesc('effective_from')
            ->get()
            ->unique('owner_id')
            ->keyBy('owner_id');

        return Store::query()
            ->with(['reseller.owner', 'channel'])
            ->join('resellers', 'resellers.id', '=', 'stores.reseller_id')
            ->join('owners', 'owners.id', '=', 'resellers.owner_id')
            ->orderBy('owners.code')->orderBy('resellers.name')->orderBy('stores.name')
            ->select('stores.*')
            ->get()
            ->map(function (Store $store) use ($tiers): array {
                $reseller = $store->reseller;
                $owner = $reseller->owner;
                $tier = $tiers->get($owner->id);
                $changed = collect([$store->updated_at, $reseller->updated_at, $owner->updated_at])->filter()->max();

                return [
                    $owner->code, $owner->name, $owner->phone,
                    $tier?->priceTier?->code, $tier?->effective_from?->toDateString(),
                    $reseller->name, (float) $reseller->opening_balance, $reseller->opening_balance_date?->toDateString(),
                    $store->name, $store->channel?->code, $store->channel?->name, $store->channel?->kind?->value,
                    $store->external_ref, $store->is_active ? 'Ya' : 'Tidak',
                    $store->id, $reseller->id, Spreadsheet::stamp($changed),
                ];
            })
            ->all();
    }

    /**
     * Validasi format satu baris (tanpa menyentuh database).
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
            'store_id' => $text('store_id') !== '' ? (int) $text('store_id') : null,
            'reseller_id' => $text('reseller_id') !== '' ? (int) $text('reseller_id') : null,
            'exported_at' => null,
            'tier' => null,
            'tier_from' => null,
            'opening_balance' => null,
            'opening_balance_date' => null,
            'channel_kind' => null,
            'is_active' => null,
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

        $row['exported_at'] = Spreadsheet::parseStamp($input['exported_at'] ?? null);

        if ($text('tier') !== '') {
            $row['tier'] = $text('tier');
            $row['tier_from'] = Spreadsheet::parseDate($input['tier_from'] ?? null);

            if (mb_strlen($row['tier']) > 20) {
                $errors[] = 'TIER maksimal 20 karakter';
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
     */
    private function storeRow(array $row): void
    {
        $owner = $this->owner($row);
        $this->tier($owner, $row);
        $channel = $this->channel($row);
        $reseller = $this->reseller($owner, $row);
        $store = $row['store_id']
            ? Store::withTrashed()->find($row['store_id']) ?? throw new BusinessRuleException("ID TOKO {$row['store_id']} tidak ditemukan; kosongkan ID untuk toko baru")
            : $this->findCi(Store::withTrashed(), 'name', $row['store']);

        if ($store?->trashed()) {
            throw new BusinessRuleException("toko \"{$store->name}\" sudah dihapus");
        }

        if ($store === null) {
            Store::create([
                'reseller_id' => $reseller->id,
                'channel_id' => $channel->id,
                'name' => $row['store'],
                'external_ref' => $row['external_ref'],
                'is_active' => $row['is_active'] ?? true,
            ]);
            $this->bump('Toko', 'created');

            return;
        }

        if ($store->reseller_id !== $reseller->id) {
            throw new BusinessRuleException("toko \"{$store->name}\" milik reseller lain; pindah reseller tidak bisa lewat Excel (ubah di menu Toko)");
        }

        $this->warnIfChangedSinceExport($store, $row, "toko \"{$store->name}\"");
        $this->ensureUniqueName(Store::withTrashed(), $row['store'], $store, 'toko');

        $store->fill(array_filter([
            'name' => $row['store'],
            'channel_id' => $channel->id,
            'external_ref' => $row['external_ref'],
            'is_active' => $row['is_active'],
        ], fn ($value): bool => $value !== null));

        $this->saveIfDirty($store, 'Toko');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function owner(array $row): Owner
    {
        $owner = $this->findCi(Owner::withTrashed(), 'code', $row['owner_code']);

        if ($owner?->trashed()) {
            throw new BusinessRuleException("pemilik {$row['owner_code']} sudah dihapus");
        }

        if ($owner === null) {
            if ($row['owner_name'] === '') {
                throw new BusinessRuleException("pemilik {$row['owner_code']} belum terdaftar, NAMA PEMILIK wajib diisi");
            }

            $this->bump('Pemilik', 'created');

            return Owner::create(['code' => $row['owner_code'], 'name' => $row['owner_name'], 'phone' => $row['owner_phone']]);
        }

        $this->warnIfChangedSinceExport($owner, $row, "pemilik {$owner->code}");
        $owner->fill(array_filter(['name' => $row['owner_name'] ?: null, 'phone' => $row['owner_phone']], fn ($value): bool => $value !== null));
        $this->saveIfDirty($owner, 'Pemilik', countSkipped: false);

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function tier(Owner $owner, array $row): void
    {
        if ($row['tier'] === null) {
            return;
        }

        $tier = $this->findCi(PriceTier::query(), 'code', $row['tier']) ?? $this->findCi(PriceTier::query(), 'name', $row['tier']);

        if ($tier === null) {
            $tier = PriceTier::create(['code' => Str::upper($row['tier']), 'name' => self::NEW_TIER_NAME, 'adjustment_pct' => self::NEW_TIER_ADJUSTMENT]);
            $this->bump('Tier Harga', 'created');
            $this->pendingWarnings[] = "tier {$tier->code} dibuat otomatis (nama \"".self::NEW_TIER_NAME.'", +'.(self::NEW_TIER_ADJUSTMENT * 100).'% dari harga dasar) — atur di menu Tier Harga.';
        }

        $history = $owner->tierHistories()->whereDate('effective_from', $row['tier_from'])->first();

        if ($history === null) {
            $owner->tierHistories()->create(['price_tier_id' => $tier->id, 'effective_from' => $row['tier_from'], 'note' => 'Impor toko']);
            $this->bump('Riwayat Tier', 'created');
        } elseif ($history->price_tier_id !== $tier->id) {
            $history->update(['price_tier_id' => $tier->id]);
            $this->bump('Riwayat Tier', 'updated');
            $this->pendingWarnings[] = "tier pemilik {$owner->code} mulai {$row['tier_from']} diganti ke {$tier->code}; pesanan baru memakai tier ini.";
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function channel(array $row): Channel
    {
        $channel = $this->findCi(Channel::query(), 'code', $row['channel_code']);

        if ($channel !== null) {
            return $channel;
        }

        if ($row['channel_kind'] === null) {
            throw new BusinessRuleException("channel {$row['channel_code']} belum terdaftar, JENIS CHANNEL wajib diisi");
        }

        $this->bump('Channel', 'created');

        return Channel::create(['code' => $row['channel_code'], 'name' => $row['channel_name'] ?: $row['channel_code'], 'kind' => $row['channel_kind']]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function reseller(Owner $owner, array $row): Reseller
    {
        $reseller = $row['reseller_id']
            ? Reseller::withTrashed()->find($row['reseller_id']) ?? throw new BusinessRuleException("ID RESELLER {$row['reseller_id']} tidak ditemukan; kosongkan ID untuk reseller baru")
            : $this->findCi(Reseller::withTrashed(), 'name', $row['reseller']);

        if ($reseller?->trashed()) {
            throw new BusinessRuleException("reseller \"{$reseller->name}\" sudah dihapus");
        }

        if ($reseller === null) {
            $this->bump('Reseller', 'created');

            return Reseller::create([
                'owner_id' => $owner->id,
                'name' => $row['reseller'],
                'opening_balance' => $row['opening_balance'] ?? 0,
                'opening_balance_date' => $row['opening_balance_date'],
            ]);
        }

        if ($reseller->owner_id !== $owner->id) {
            throw new BusinessRuleException("reseller \"{$reseller->name}\" milik pemilik lain; pindah pemilik tidak bisa lewat Excel (ubah di menu Reseller)");
        }

        $this->warnIfChangedSinceExport($reseller, $row, "reseller \"{$reseller->name}\"");
        $this->ensureUniqueName(Reseller::withTrashed(), $row['reseller'], $reseller, 'reseller');

        $reseller->fill(array_filter([
            'name' => $row['reseller'],
            'opening_balance' => $row['opening_balance'],
            'opening_balance_date' => $row['opening_balance_date'],
        ], fn ($value): bool => $value !== null));

        if ($reseller->isDirty('opening_balance')) {
            $this->pendingWarnings[] = "saldo awal reseller \"{$reseller->name}\" diubah; piutang reseller ikut berubah.";
        }

        $this->saveIfDirty($reseller, 'Reseller', countSkipped: false);

        return $reseller;
    }

    /**
     * Nama baru tidak boleh sama dengan data lain (ganti nama lewat ID).
     *
     * @param  Builder<Model>  $query
     */
    private function ensureUniqueName(Builder $query, string $name, Model $current, string $label): void
    {
        $other = $this->findCi($query, 'name', $name);

        if ($other !== null && $other->getKey() !== $current->getKey()) {
            throw new BusinessRuleException("nama {$label} \"{$name}\" sudah dipakai {$label} lain");
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function warnIfChangedSinceExport(Model $model, array $row, string $label): void
    {
        if ($row['exported_at'] instanceof Carbon && $model->updated_at?->gt($row['exported_at']->copy()->addSecond())) {
            $this->pendingWarnings[] = "{$label} diubah di sistem setelah file di-export ({$model->updated_at->format('d/m/Y H:i')}); isi file dipakai.";
        }
    }

    private function saveIfDirty(Model $model, string $label, bool $countSkipped = true): void
    {
        if ($model->isDirty()) {
            $model->save();
            $this->bump($label, 'updated');
        } elseif ($countSkipped) {
            $this->bump($label, 'skipped');
        }
    }

    private function bump(string $label, string $kind): void
    {
        $this->pending[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->pending[$label][$kind]++;
    }

    /**
     * Cari tanpa membedakan huruf besar/kecil.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function findCi(Builder $query, string $column, string $value): ?Model
    {
        return $query->whereRaw("lower({$column}) = ?", [Str::lower($value)])->first();
    }

    private function parseChannelKind(string $value): ?ChannelKind
    {
        $value = Str::lower($value);

        return ChannelKind::tryFrom($value)
            ?? collect(ChannelKind::cases())->first(fn (ChannelKind $kind): bool => Str::lower($kind->getLabel()) === $value)
            ?? ($value === 'wa' ? ChannelKind::Chat : null);
    }
}
