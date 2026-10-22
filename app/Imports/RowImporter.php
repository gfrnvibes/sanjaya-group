<?php

namespace App\Imports;

use App\Enums\WarehouseKind;
use App\Exceptions\BusinessRuleException;
use App\Models\CashAccount;
use App\Models\ExpenseCategory;
use App\Models\ProductVariant;
use App\Models\Reseller;
use App\Models\SkuAlias;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tailor;
use App\Models\Warehouse;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetCells;
use App\Support\SpreadsheetRow;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Dasar impor 1 sheet untuk sebuah resource:
 *  - header dicari otomatis, baris kosong dilewati, nomor baris = nomor baris Excel;
 *  - tiap baris (atau dokumen, bila groupKey() mengelompokkan) disimpan dalam transaksinya sendiri:
 *    baris valid tersimpan, baris error dilaporkan;
 *  - data bantu (gudang, rekening, kategori biaya, supplier, penjahit) dibuat otomatis bila belum ada.
 */
abstract class RowImporter
{
    protected const NOTE = 'Impor';

    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $stats = [];

    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $pending = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $pendingWarnings = [];

    /** @var array<string, int> sisa data yang sudah ada per sidik (untuk melewati baris dobel) */
    private array $existingLeft = [];

    /**
     * Nama data, dipakai untuk judul sheet, nama file, dan label ringkasan (mis. "Biaya Iklan").
     */
    abstract public function title(): string;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> LABEL HEADER => [kunci, wajib, keterangan]
     */
    abstract public function columns(): array;

    /**
     * Izin yang dibutuhkan untuk mengimpor (lihat ApplicationAccess).
     */
    public function permission(): string
    {
        return 'kelola stok';
    }

    /**
     * Kunci header yang harus ada agar sheet dikenali.
     *
     * @return list<string|list<string>>
     */
    abstract protected function requiredHeaders(): array;

    /**
     * Simpan satu dokumen (berisi 1 baris bila groupKey() mengembalikan null). Dipanggil di dalam transaksi.
     *
     * @param  Collection<int, SpreadsheetRow>  $rows
     */
    abstract protected function save(Collection $rows): void;

    /**
     * Kunci pengelompokan baris menjadi satu dokumen (mis. no. bukti). Null = tiap baris berdiri sendiri.
     */
    protected function groupKey(SpreadsheetRow $row): ?string
    {
        return null;
    }

    /**
     * Catatan tambahan di sheet Petunjuk.
     *
     * @return list<string>
     */
    protected function notes(): array
    {
        return [];
    }

    /**
     * Sheet referensi di template: nama => [lebar kolom, baris (baris pertama = header)].
     *
     * @return array<string, array{0: list<float>, 1: list<list<mixed>>}>
     */
    protected function referenceSheets(): array
    {
        return [];
    }

    /**
     * @return array{rows: int, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    public function import(string $path, string $fileName): array
    {
        $this->stats = [];
        $this->errors = [];
        $this->warnings = [];
        $this->existingLeft = [];

        try {
            $lines = Spreadsheet::read(
                $path,
                $fileName,
                collect($this->columns())->map(fn (array $column): string => $column[0])->all(),
                $this->requiredHeaders(),
                'Header tidak ditemukan. Pastikan ada kolom '.implode(', ', $this->requiredLabels()).' (gunakan template).',
            );
        } catch (BusinessRuleException $exception) {
            return ['rows' => 0, 'stats' => [], 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        if ($lines === []) {
            return ['rows' => 0, 'stats' => [], 'errors' => ['Tidak ada baris data yang bisa diimpor.'], 'warnings' => []];
        }

        collect($lines)
            ->map(fn (array $line): SpreadsheetRow => new SpreadsheetRow($line['values'], $line['line']))
            ->groupBy(fn (SpreadsheetRow $row): string => $this->groupKey($row) ?? '#'.$row->line)
            ->each(fn (Collection $rows) => $this->attempt($rows));

        return [
            'rows' => count($lines),
            'stats' => $this->stats,
            'errors' => $this->errors,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * Label kolom yang diisi sistem (header hijau, tidak perlu diubah), mis. STOK SISTEM.
     *
     * @return list<string>
     */
    protected function systemColumns(): array
    {
        return [];
    }

    /**
     * Baris data untuk export (urutan = columns()). Null = resource ini tidak punya export data.
     *
     * @return iterable<list<mixed>>|null
     */
    protected function exportRows(): ?iterable
    {
        return null;
    }

    public function hasExport(): bool
    {
        return $this->exportRows() !== null;
    }

    /**
     * Template kosong + sheet Petunjuk + sheet referensi. Mengembalikan path file sementara.
     */
    public function template(): string
    {
        return $this->workbook([]);
    }

    /**
     * Export data dengan layout yang sama dengan template: diedit lalu diimpor ulang.
     */
    public function export(): string
    {
        return $this->workbook($this->exportRows() ?? []);
    }

    /**
     * @param  iterable<list<mixed>>  $rows
     */
    private function workbook(iterable $rows): string
    {
        $path = Spreadsheet::tempPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(Spreadsheet::sheetName($this->title()));

        foreach (array_keys($this->columns()) as $index => $label) {
            $sheet->setColumnWidth(max(12, min(36, mb_strlen($label) + 6)), $index + 1);
        }

        $writer->addRow(Spreadsheet::headerRow(collect(array_keys($this->columns()))
            ->mapWithKeys(fn (string $label): array => [$label => ! in_array($label, $this->systemColumns(), true)])
            ->all()));

        foreach ($rows as $row) {
            $writer->addRow(SpreadsheetCells::row($row));
        }

        Spreadsheet::addSheet($writer, 'Petunjuk', [26, 16, 100], [
            ['KOLOM', 'WAJIB', 'KETERANGAN'],
            ...collect($this->columns())->map(fn (array $column, string $label): array => [$label, $column[1], $column[2]])->values()->all(),
            ['', '', ''],
            ...collect([
                'Baris yang valid tetap disimpan; baris error dilaporkan setelah impor.',
                ...$this->notes(),
            ])->map(fn (string $note, int $index): array => [$index === 0 ? 'Catatan' : '', '', $note])->all(),
        ]);

        foreach ($this->referenceSheets() as $name => [$widths, $sheetRows]) {
            Spreadsheet::addSheet($writer, $name, $widths, $sheetRows);
        }

        $writer->close();

        return $path;
    }

    // ---------------------------------------------------------------- bantuan untuk turunan

    /**
     * Jalankan $callback untuk tiap baris dokumen; error per baris dikumpulkan lalu dilempar sekaligus.
     *
     * @template TResult
     *
     * @param  Collection<int, SpreadsheetRow>  $rows
     * @param  Closure(SpreadsheetRow): TResult  $callback
     * @return list<TResult>
     */
    protected function eachLine(Collection $rows, Closure $callback): array
    {
        $results = [];
        $errors = [];

        foreach ($rows as $row) {
            try {
                $results[] = $callback($row);
            } catch (BusinessRuleException $exception) {
                $errors[] = ($rows->count() > 1 ? "baris {$row->line}: " : '').rtrim($exception->getMessage(), '.');
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException(implode('; ', $errors).'.');
        }

        return $results;
    }

    /**
     * Baris dobel: true bila data identik sudah ada (dihitung sekali per sidik; baris identik dalam file
     * tetap disimpan sebanyak yang belum ada di sistem).
     *
     * @param  Closure(): int  $countExisting
     */
    protected function alreadyExists(string $signature, Closure $countExisting): bool
    {
        $this->existingLeft[$signature] ??= $countExisting();

        if ($this->existingLeft[$signature] > 0) {
            $this->existingLeft[$signature]--;

            return true;
        }

        return false;
    }

    protected function bump(string $kind, ?string $label = null): void
    {
        $label ??= $this->title();
        $this->pending[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->pending[$label][$kind]++;
    }

    protected function warn(string $message): void
    {
        $this->pendingWarnings[] = $message;
    }

    protected function saveIfDirty(Model $model): void
    {
        if ($model->isDirty()) {
            $model->save();
            $this->bump('updated');
        } else {
            $this->bump('skipped');
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    protected function findCi(Builder $query, string $column, string $value): ?Model
    {
        return $query->whereRaw("lower({$column}) = ?", [Str::lower(trim($value))])->first();
    }

    protected function store(string $name): Store
    {
        return $this->findCi(Store::query(), 'name', $name)
            ?? throw new BusinessRuleException("toko \"{$name}\" tidak ditemukan (daftarkan lewat Import Toko)");
    }

    protected function reseller(string $name): Reseller
    {
        return $this->findCi(Reseller::query(), 'name', $name)
            ?? throw new BusinessRuleException("reseller \"{$name}\" tidak ditemukan (daftarkan lewat Import Toko)");
    }

    /**
     * Varian lewat SKU varian, atau SKU BigSeller (pemetaan SKU).
     */
    protected function variant(string $sku): ProductVariant
    {
        $variant = $this->findCi(ProductVariant::query(), 'sku', $sku)
            ?? $this->findCi(SkuAlias::query(), 'alias', $sku)?->variant;

        return $variant ?? throw new BusinessRuleException("SKU \"{$sku}\" tidak ditemukan (daftarkan lewat Import Katalog)");
    }

    /**
     * Gudang BARANG JADI dari kode/nama; dibuat otomatis (jenis barang jadi) bila belum ada.
     * Kosong = gudang default barang jadi. Gudang bahan ditolak oleh StockLedger.
     */
    protected function warehouse(string $value): Warehouse
    {
        if ($value === '') {
            $warehouse = Warehouse::defaultForGoods();

            if ($warehouse !== null) {
                return $warehouse;
            }

            $value = 'Gudang Barang Jadi';
        }

        $warehouse = $this->findCi(Warehouse::query(), 'code', $value) ?? $this->findCi(Warehouse::query(), 'name', $value);

        if ($warehouse === null) {
            $warehouse = Warehouse::create([
                'code' => $this->uniqueCode(Warehouse::class, $value, 20),
                'name' => Str::limit($value, 80, ''),
                'kind' => WarehouseKind::BarangJadi,
            ]);
            $this->bump('created', 'Gudang');
            $this->warn("gudang \"{$warehouse->name}\" dibuat otomatis (kode {$warehouse->code}).");
        }

        return $warehouse;
    }

    /**
     * Rekening/kas lewat nama; dibuat otomatis bila belum ada.
     */
    protected function cashAccount(string $name): CashAccount
    {
        $account = $this->findCi(CashAccount::query(), 'name', $name);

        if ($account === null) {
            $account = CashAccount::create(['name' => Str::limit($name, 80, '')]);
            $this->bump('created', 'Rekening / Kas');
            $this->warn("rekening/kas \"{$account->name}\" dibuat otomatis — lengkapi bank & pemilik rekening.");
        }

        return $account;
    }

    /**
     * Kategori biaya lewat kode/nama; dibuat otomatis bila belum ada.
     */
    protected function expenseCategory(string $value): ExpenseCategory
    {
        $category = $this->findCi(ExpenseCategory::query(), 'code', $value) ?? $this->findCi(ExpenseCategory::query(), 'name', $value);

        if ($category === null) {
            $category = ExpenseCategory::create(['code' => $this->uniqueCode(ExpenseCategory::class, $value, 30, '_'), 'name' => Str::limit($value, 80, '')]);
            $this->bump('created', 'Kategori Biaya');
        }

        return $category;
    }

    protected function supplier(string $name, ?string $phone = null): Supplier
    {
        $supplier = $this->findCi(Supplier::query(), 'name', $name);

        if ($supplier === null) {
            $supplier = Supplier::create(['name' => Str::limit($name, 120, ''), 'phone' => $phone]);
            $this->bump('created', 'Supplier');
        }

        return $supplier;
    }

    protected function tailor(string $name): Tailor
    {
        $tailor = $this->findCi(Tailor::query(), 'name', $name);

        if ($tailor === null) {
            $tailor = Tailor::create(['name' => Str::limit($name, 120, '')]);
            $this->bump('created', 'Penjahit');
        }

        return $tailor;
    }

    /**
     * Kode unik dari teks bebas.
     *
     * @param  class-string<Model>  $model
     */
    protected function uniqueCode(string $model, string $text, int $max, string $separator = '-'): string
    {
        $base = Str::upper(Str::slug($text, $separator)) ?: 'X';
        $candidate = Str::limit($base, $max, '');

        for ($i = 2; $this->findCi($model::query(), 'code', $candidate) !== null; $i++) {
            $candidate = Str::limit($base, $max - strlen((string) $i), '').$i;
        }

        return $candidate;
    }

    // ---------------------------------------------------------------- internal

    /**
     * @param  Collection<int, SpreadsheetRow>  $rows
     */
    private function attempt(Collection $rows): void
    {
        $this->pending = [];
        $this->pendingWarnings = [];
        $lines = $rows->pluck('line');
        $where = 'Baris '.($lines->count() > 1 ? $lines->first().'-'.$lines->last() : $lines->first());

        try {
            DB::transaction(fn () => $this->save($rows->values()));

            foreach ($this->pending as $label => $counts) {
                $this->stats[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

                foreach ($counts as $kind => $count) {
                    $this->stats[$label][$kind] += $count;
                }
            }

            foreach ($this->pendingWarnings as $warning) {
                $this->warnings[] = "{$where}: {$warning}";
            }
        } catch (BusinessRuleException $exception) {
            $this->errors[] = "{$where}: ".rtrim($exception->getMessage(), '.').'.';
        } catch (QueryException $exception) {
            report($exception);
            $this->errors[] = "{$where}: gagal disimpan. Periksa data atau hubungi administrator.";
        } catch (Throwable $exception) {
            report($exception);
            $this->errors[] = "{$where}: terjadi kesalahan. Hubungi administrator.";
        }

        $this->pending = [];
        $this->pendingWarnings = [];
    }

    /**
     * @return list<string>
     */
    private function requiredLabels(): array
    {
        $labels = collect($this->columns())->mapWithKeys(fn (array $column, string $label): array => [$column[0] => $label]);

        return collect($this->requiredHeaders())
            ->map(fn (string|array $keys): string => collect((array) $keys)->map(fn (string $key): string => $labels[$key] ?? $key)->implode(' / '))
            ->all();
    }
}
