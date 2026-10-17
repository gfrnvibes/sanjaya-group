<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\SalesImportBatch;
use App\Models\SkuAlias;
use App\Models\Store;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Impor sheet "INPUT ORDERAN RESELLER": 1 baris = 1 pesanan.
 * Header dicari otomatis (boleh ada judul di atasnya & kolom otomatis di sampingnya).
 * Semua baris divalidasi dulu; bila ada 1 error, tidak ada yang disimpan.
 */
class SalesOrderImporter
{
    private const HEADER_SCAN_ROWS = 15;

    /** @var Collection<string, Store>|null */
    private ?Collection $stores = null;

    /** @var Collection<string, ProductVariant>|null */
    private ?Collection $variants = null;

    /** @var Collection<string, int>|null */
    private ?Collection $aliases = null;

    public function __construct(private SalesOrderService $orders) {}

    /**
     * @param  array{warehouse_id: int, status: string, source: string}  $options
     * @return array{batch: SalesImportBatch, created: int, errors: list<string>}
     */
    public function import(string $path, string $fileName, array $options): array
    {
        $batch = SalesImportBatch::create([
            'source' => $options['source'] ?? SalesImportSource::Manual->value,
            'file_name' => $fileName,
            'status' => ImportBatchStatus::Pending,
            'imported_by' => auth()->id(),
        ]);

        try {
            [$rows, $errors] = $this->parse($path, pathinfo($fileName, PATHINFO_EXTENSION));
        } catch (BusinessRuleException $exception) {
            [$rows, $errors] = [[], [$exception->getMessage()]];
        }

        $created = 0;

        if ($errors === [] && $rows !== []) {
            $created = $this->store($rows, $batch, $options, $errors);
        } elseif ($errors === []) {
            $errors[] = 'Tidak ada baris data yang bisa diimpor.';
        }

        $batch->update([
            'status' => $errors === [] ? ImportBatchStatus::Done : ImportBatchStatus::Failed,
            'row_count' => count($rows),
            'error_count' => count($errors),
        ]);

        return ['batch' => $batch, 'created' => $created, 'errors' => $errors];
    }

    /**
     * @param  list<array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float}>  $rows
     * @param  array{warehouse_id: int, status: string}  $options
     * @param  list<string>  $errors
     */
    private function store(array $rows, SalesImportBatch $batch, array $options, array &$errors): int
    {
        $created = 0;
        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                // SalesOrderService memakai transaksi bersarang (savepoint), jadi baris gagal tidak merusak transaksi luar.
                try {
                    $this->orders->create([
                        'store_id' => $row['store']->id,
                        'warehouse_id' => $options['warehouse_id'],
                        'order_no' => sprintf('IMP%d-%04d', $batch->id, $row['line']),
                        'order_date' => $row['order_date'],
                        'status' => $options['status'] ?? SalesOrderStatus::Completed->value,
                        'import_batch_id' => $batch->id,
                    ], [[
                        'variant_id' => $row['variant_id'],
                        'sku_raw' => $row['sku_raw'],
                        'qty' => $row['qty'],
                        'revenue' => $row['revenue'],
                    ]]);
                    $created++;
                } catch (BusinessRuleException $exception) {
                    $errors[] = "Baris {$row['line']}: {$exception->getMessage()}";
                } catch (QueryException $exception) {
                    $errors[] = "Baris {$row['line']}: gagal disimpan ({$this->firstLine($exception)}).";
                }
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($errors !== []) {
            DB::rollBack();

            return 0;
        }

        DB::commit();

        return $created;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function parse(string $path, string $extension): array
    {
        $reader = $this->reader($path, strtolower($extension));
        $reader->open($path);

        $rows = [];
        $errors = [];
        $columns = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = $row->toArray();

                    if ($columns === null) {
                        $columns = $this->detectHeader($values);

                        if ($columns === null && $line >= self::HEADER_SCAN_ROWS) {
                            break;
                        }

                        continue;
                    }

                    $input = [];
                    foreach ($columns as $key => $index) {
                        $value = $values[$index] ?? null;
                        $input[$key] = is_string($value) ? trim($value) : $value;
                    }

                    if (collect($input)->every(fn ($value): bool => $value === null || $value === '')) {
                        continue;
                    }

                    $parsed = $this->parseRow($input, $line, $errors);

                    if ($parsed !== null) {
                        $rows[] = $parsed;
                    }
                }

                break; // hanya sheet pertama
            }
        } finally {
            $reader->close();
        }

        if ($columns === null) {
            throw new BusinessRuleException('Header tidak ditemukan. Pastikan ada kolom TANGGAL, NAMA TOKO, SKU VARIAN / SKU BIG SELLER, dan QTY (gunakan template).');
        }

        return [$rows, $errors];
    }

    /**
     * @param  list<mixed>  $values
     * @return array<string, int>|null kunci input => indeks kolom
     */
    private function detectHeader(array $values): ?array
    {
        $map = [
            'TANGGAL' => 'order_date',
            'NAMA TOKO' => 'store',
            'SKU VARIAN' => 'sku',
            'SKU BIG SELLER' => 'sku_bigseller',
            'SKU BIGSELLER' => 'sku_bigseller',
            'QTY' => 'qty',
            'PENDAPATAN TOKO' => 'revenue',
        ];

        $columns = [];

        foreach ($values as $index => $value) {
            $label = Str::of((string) (is_scalar($value) ? $value : ''))->upper()->squish()->toString();

            if (isset($map[$label]) && ! isset($columns[$map[$label]])) {
                $columns[$map[$label]] = $index;
            }
        }

        $hasSku = isset($columns['sku']) || isset($columns['sku_bigseller']);

        return isset($columns['order_date'], $columns['store'], $columns['qty']) && $hasSku ? $columns : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     * @return array{line: int, order_date: string, store: Store, variant_id: int, sku_raw: ?string, qty: int, revenue: ?float}|null
     */
    private function parseRow(array $input, int $line, array &$errors): ?array
    {
        $rowErrors = [];

        $date = $this->parseDate($input['order_date'] ?? null);
        if ($date === null) {
            $rowErrors[] = 'TANGGAL kosong atau formatnya tidak dikenali';
        }

        $storeName = (string) ($input['store'] ?? '');
        $store = $this->stores()->get(Str::lower($storeName));
        if ($storeName === '') {
            $rowErrors[] = 'NAMA TOKO kosong';
        } elseif ($store === null) {
            $rowErrors[] = "toko \"{$storeName}\" tidak ditemukan";
        } elseif (! $store->is_active) {
            $rowErrors[] = "toko \"{$storeName}\" tidak aktif";
        }

        $sku = (string) ($input['sku'] ?? '');
        $skuBigSeller = (string) ($input['sku_bigseller'] ?? '');
        $variant = $this->resolveVariant($sku, $skuBigSeller);
        if ($sku === '' && $skuBigSeller === '') {
            $rowErrors[] = 'SKU VARIAN / SKU BIG SELLER kosong';
        } elseif ($variant === null) {
            $rowErrors[] = 'SKU "'.($sku ?: $skuBigSeller).'" tidak ditemukan (cek master varian / pemetaan SKU)';
        } elseif (! $variant->is_active) {
            $rowErrors[] = "SKU \"{$variant->sku}\" tidak aktif";
        }

        $qty = $this->parseNumber($input['qty'] ?? null);
        if ($qty === null || $qty < 1 || floor($qty) != $qty) {
            $rowErrors[] = 'QTY harus bilangan bulat minimal 1';
        }

        $revenueRaw = $input['revenue'] ?? null;
        $revenue = $this->parseNumber($revenueRaw);
        if ($revenueRaw !== null && $revenueRaw !== '' && ($revenue === null || $revenue < 0)) {
            $rowErrors[] = 'PENDAPATAN TOKO harus angka >= 0';
        }

        if ($rowErrors !== []) {
            $errors[] = "Baris {$line}: ".implode('; ', $rowErrors).'.';

            return null;
        }

        return [
            'line' => $line,
            'order_date' => $date,
            'store' => $store,
            'variant_id' => $variant->id,
            'sku_raw' => $skuBigSeller !== '' ? $skuBigSeller : $sku,
            'qty' => (int) $qty,
            'revenue' => $revenue,
        ];
    }

    private function resolveVariant(string $sku, string $skuBigSeller): ?ProductVariant
    {
        if ($sku !== '') {
            return $this->variants()->get(Str::lower($sku));
        }

        if ($skuBigSeller === '') {
            return null;
        }

        $variantId = $this->aliases()->get(Str::lower($skuBigSeller));

        return $variantId !== null
            ? $this->variants()->firstWhere('id', $variantId)
            : $this->variants()->get(Str::lower($skuBigSeller));
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_int($value) || is_float($value)) {
            // Nomor seri tanggal Excel.
            return $value > 0 ? Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString() : null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d H:i:s', 'd/m/Y H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Angka dari sel Excel atau teks seperti "Rp 52.492" / "52,492.50".
     */
    private function parseNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = preg_replace('/[^\d.,\-]/', '', (string) $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            // Pemisah desimal = yang muncul paling akhir.
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        } elseif ($hasComma) {
            $value = preg_match('/^-?\d{1,3}(,\d{3})+$/', $value) ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Baris kosong dipertahankan agar nomor baris di pesan error sama dengan nomor baris di Excel.
     */
    private function reader(string $path, string $extension): ReaderInterface
    {
        switch ($extension) {
            case 'xlsx':
                $options = new XlsxOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

                return new XlsxReader($options);
            case 'ods':
                $options = new OdsOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

                return new OdsReader($options);
            case 'csv':
            case 'txt':
                $options = new CsvOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $firstLine = (string) fgets($handle = fopen($path, 'r'));
                fclose($handle);
                $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

                return new CsvReader($options);
            default:
                throw new BusinessRuleException('Format file tidak didukung. Gunakan .xlsx, .csv, atau .ods.');
        }
    }

    /** @return Collection<string, Store> */
    private function stores(): Collection
    {
        return $this->stores ??= Store::query()->get()->keyBy(fn (Store $store): string => Str::lower(trim($store->name)));
    }

    /** @return Collection<string, ProductVariant> */
    private function variants(): Collection
    {
        return $this->variants ??= ProductVariant::query()->get()->keyBy(fn (ProductVariant $variant): string => Str::lower(trim($variant->sku)));
    }

    /** @return Collection<string, int> */
    private function aliases(): Collection
    {
        return $this->aliases ??= SkuAlias::query()->get()->mapWithKeys(fn (SkuAlias $alias): array => [Str::lower(trim($alias->alias)) => $alias->variant_id]);
    }

    private function firstLine(Throwable $exception): string
    {
        return Str::limit(strtok($exception->getMessage(), "\n") ?: 'kesalahan database', 160);
    }
}
