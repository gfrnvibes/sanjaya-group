<?php

namespace App\Services;

use App\Enums\BaseUom;
use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use App\Enums\MaterialKind;
use App\Enums\MaterialMovementType;
use App\Enums\ProductKind;
use App\Enums\WarehouseKind;
use App\Exceptions\BusinessRuleException;
use App\Imports\ImportIssueLog;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Color;
use App\Models\LaborType;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\MaterialType;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\SkuAlias;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Spreadsheet;
use App\Support\SpreadsheetRow;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor workbook KATALOG untuk grup Produk serta Bahan & HPP.
 * Jenis sheet dikenali dari header (nama sheet bebas, CSV 1 sheet juga bisa), diproses berurutan:
 *  - Bahan     (1 baris = 1 bahan; + jenis bahan, warna, stok awal)
 *  - Produk    (1 baris = 1 varian; + kategori, model, warna, ukuran, SKU BigSeller, HPP, harga reseller)
 *  - Resep BOM (1 baris = 1 komponen; dikelompokkan per model + ukuran + berlaku mulai)
 *  - Pembelian (1 baris = 1 item; dikelompokkan per tanggal + supplier + no ref + gudang)
 * Master (model, varian, bahan) yang sudah ada DIPERBARUI dari sel yang terisi; sel kosong = tidak diubah.
 * HPP & harga reseller TIDAK diimpor: dihitung otomatis dari resep + margin untuk varian yang terdampak.
 * Tiap baris (Bahan/Produk) atau dokumen (Resep/Pembelian) disimpan terpisah: yang valid tersimpan.
 */
class CatalogImporter
{
    public const SHEETS = [
        'materials' => [
            'title' => 'Bahan',
            'headers' => [
                'KODE JENIS BAHAN' => 'type_code',
                'NAMA JENIS BAHAN' => 'type_name',
                'KATEGORI BAHAN' => 'type_kind',
                'KODE WARNA' => 'color_code',
                'NAMA WARNA' => 'color_name',
                'KODE BAHAN' => 'code',
                'SATUAN DASAR' => 'base_uom',
                'SATUAN BELI' => 'pack_uom',
                'ISI PER SATUAN BELI' => 'qty_per_pack',
                'MIN STOK' => 'min_stock',
                'AKTIF' => 'is_active',
                'STOK AWAL' => 'opening_qty',
                'HARGA PER SATUAN DASAR' => 'opening_cost',
                'GUDANG' => 'warehouse',
                'TGL STOK AWAL' => 'opening_date',
            ],
            // Kolom sistem (header hijau, jangan diubah): ID untuk ganti kode, DIUBAH TERAKHIR untuk deteksi perubahan.
            'system' => ['ID BAHAN' => 'material_id', 'DIUBAH TERAKHIR' => 'exported_at'],
            'required' => ['type_code', 'base_uom'],
        ],
        'products' => [
            'title' => 'Produk',
            'headers' => [
                'KATEGORI' => 'category',
                'KODE MODEL' => 'model_code',
                'NAMA MODEL' => 'model_name',
                'JENIS PRODUK' => 'model_kind',
                'MARGIN MODEL %' => 'model_margin',
                'KODE WARNA' => 'color_code',
                'NAMA WARNA' => 'color_name',
                'UKURAN' => 'size',
                'SKU VARIAN' => 'sku',
                'NAMA VARIAN' => 'name',
                'MIN STOK' => 'min_stock',
                'MARGIN VARIAN %' => 'margin',
                'AKTIF' => 'is_active',
                'SKU BIG SELLER' => 'aliases',
                'BIAYA PACKING' => 'packing_cost',
            ],
            'system' => ['ID VARIAN' => 'variant_id', 'DIUBAH TERAKHIR' => 'exported_at'],
            'required' => ['model_code', 'sku'],
        ],
        'boms' => [
            'title' => 'Resep BOM',
            'headers' => [
                'KODE MODEL' => 'model_code',
                'UKURAN' => 'size',
                'BERLAKU MULAI' => 'effective_from',
                'CATATAN' => 'note',
                'JENIS BARIS' => 'line_type',
                'KODE JENIS BAHAN' => 'type_code',
                'KODE BAHAN' => 'material_code',
                'PEMAKAIAN PER PCS' => 'qty',
                'KODE ONGKOS' => 'labor_code',
                'NAMA ONGKOS' => 'labor_name',
                'ONGKOS PER PCS' => 'labor_rate',
            ],
            'required' => ['model_code', 'line_type'],
        ],
        'purchases' => [
            'title' => 'Pembelian Bahan',
            'headers' => [
                'TANGGAL' => 'date',
                'SUPPLIER' => 'supplier',
                'NO HP SUPPLIER' => 'supplier_phone',
                'NO REF' => 'ref_no',
                'GUDANG' => 'warehouse',
                'KODE BAHAN' => 'material_code',
                'QTY BELI' => 'qty_pack',
                'QTY SATUAN DASAR' => 'qty_base',
                'TOTAL HARGA' => 'total_price',
                'CATATAN' => 'note',
            ],
            'required' => ['date', 'material_code', 'total_price'],
        ],
    ];

    private const NOTE = 'Impor katalog';

    /** Kolom komponen resep yang dibandingkan saat impor ulang. */
    private const BOM_LINE_DEFAULTS = [
        'line_type' => null, 'material_type_id' => null, 'color_mode' => null, 'material_id' => null,
        'qty_per_unit' => null, 'labor_type_id' => null, 'labor_rate' => null,
    ];

    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $stats = [];

    /** @var array<string, array{created: int, updated: int, skipped: int}> statistik baris yang sedang diproses */
    private array $pending = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> data bantu yang dibuat dengan nilai tebakan/default — perlu dicek */
    private array $warnings = [];

    /** @var list<string> peringatan baris yang sedang diproses */
    private array $pendingWarnings = [];

    /** @var array<string, array{variant_id: int, date: ?string}> varian yang harganya dihitung ulang setelah impor */
    private array $priceTriggers = [];

    /** @var array<string, array{variant_id: int, date: ?string}> pemicu harga baris yang sedang diproses */
    private array $pendingTriggers = [];

    /** @var array<string, ImportIssueLog> baris gagal per jenis sheet (masuk ke "Import Perlu Dicek") */
    private array $issues = [];

    public function __construct(private InventoryService $inventory, private PricingService $pricing) {}

    /**
     * @param  list<string>|null  $only  jenis sheet yang diproses (mis. ['products'] dari halaman Varian); null = semua
     * @return array{rows: int, sheets: list<string>, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    public function import(string $path, string $fileName, ?array $only = null): array
    {
        try {
            $sheets = Spreadsheet::readSheets($path, $fileName, collect(self::SHEETS)
                ->when($only !== null, fn (Collection $sheets) => $sheets->only($only))
                ->map(fn (array $sheet): array => ['headers' => [...$sheet['headers'], ...($sheet['system'] ?? []), ImportIssueLog::FAILURE_ID_COLUMN => 'failure_id'], 'required' => $sheet['required']])
                ->all());
        } catch (BusinessRuleException $exception) {
            return ['rows' => 0, 'sheets' => [], 'stats' => [], 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        if ($sheets === []) {
            $titles = collect(self::SHEETS)->only($only ?? array_keys(self::SHEETS))->pluck('title')->implode(' / ');

            return ['rows' => 0, 'sheets' => [], 'stats' => [], 'errors' => ["Tidak ada sheet yang dikenali. Gunakan template (sheet {$titles})."], 'warnings' => []];
        }

        return $this->process($sheets, $fileName);
    }

    /**
     * Kunci kategori "Import Perlu Dicek" untuk satu jenis sheet, mis. catalog.products.
     */
    public static function issueKey(string $type): string
    {
        return 'catalog.'.$type;
    }

    /**
     * Label header (merah + sistem) sheet katalog, urutan template.
     *
     * @return list<string>
     */
    public static function labels(string $type): array
    {
        return [...array_keys(self::SHEETS[$type]['headers']), ...array_keys(self::SHEETS[$type]['system'] ?? [])];
    }

    /**
     * Coba ulang baris dari "Import Perlu Dicek" (nilai per LABEL HEADER).
     *
     * @param  list<array{line: int, values: array<string, mixed>, failure_id: ?int}>  $rows
     * @return array{rows: int, sheets: list<string>, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings: list<string>}
     */
    public function importValues(string $type, array $rows, string $fileName = 'Perbaikan langsung'): array
    {
        $map = [...self::SHEETS[$type]['headers'], ...(self::SHEETS[$type]['system'] ?? [])];

        return $this->process([$type => array_map(fn (array $row): array => [
            'sheet' => self::SHEETS[$type]['title'],
            'line' => $row['line'],
            'values' => [
                ...collect($map)->mapWithKeys(fn (string $key, string $label): array => [$key => $row['values'][$label] ?? null])->all(),
                'failure_id' => $row['failure_id'] ?? null,
            ],
        ], $rows)], $fileName);
    }

    /**
     * @param  array<string, list<array{sheet: string, line: int, values: array<string, mixed>}>>  $sheets
     */
    private function process(array $sheets, string $fileName): array
    {
        $this->stats = [];
        $this->errors = [];
        $this->warnings = [];
        $this->priceTriggers = [];
        $this->issues = collect(array_keys($sheets))->mapWithKeys(fn (string $type): array => [$type => new ImportIssueLog(self::issueKey($type), $fileName)])->all();

        foreach ($sheets['materials'] ?? [] as $row) {
            $this->attempt($this->where('materials', $row), fn () => $this->importMaterial($row['values']), 'materials', collect([$row]));
        }

        foreach ($sheets['products'] ?? [] as $row) {
            $this->attempt($this->where('products', $row), fn () => $this->importProduct($row['values']), 'products', collect([$row]));
        }

        $this->groupRows($sheets['boms'] ?? [], fn (array $values): string => implode('|', [
            Str::lower(Spreadsheet::text($values['model_code'] ?? null)),
            Str::lower(Spreadsheet::text($values['size'] ?? null)),
            Spreadsheet::parseDate($values['effective_from'] ?? null) ?? Spreadsheet::text($values['effective_from'] ?? null),
        ]))->each(fn (Collection $rows, string $group) => $this->attempt($this->whereGroup('boms', $rows), fn () => $this->importBom($rows), 'boms', $rows, $group));

        $this->groupRows($sheets['purchases'] ?? [], fn (array $values): string => implode('|', [
            Spreadsheet::parseDate($values['date'] ?? null) ?? Spreadsheet::text($values['date'] ?? null),
            Str::lower(Spreadsheet::text($values['supplier'] ?? null)),
            Str::lower(Spreadsheet::text($values['ref_no'] ?? null)),
            Str::lower(Spreadsheet::text($values['warehouse'] ?? null)),
        ]))->each(fn (Collection $rows, string $group) => $this->attempt($this->whereGroup('purchases', $rows), fn () => $this->importPurchase($rows), 'purchases', $rows, $group));

        $this->syncPrices();

        foreach ($this->issues as $issues) {
            $issues->save();
        }

        return [
            'rows' => collect($sheets)->sum(fn (array $rows): int => count($rows)),
            'sheets' => collect(array_keys($sheets))->map(fn (string $key): string => self::SHEETS[$key]['title'])->all(),
            'stats' => $this->stats,
            'errors' => $this->errors,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function importMaterial(array $input): void
    {
        $v = $this->reader($input);
        $v->requireText(['type_code' => 'KODE JENIS BAHAN']);
        $v->maxLength(['type_code' => ['KODE JENIS BAHAN', 30], 'type_name' => ['NAMA JENIS BAHAN', 80], 'code' => ['KODE BAHAN', 60], 'pack_uom' => ['SATUAN BELI', 10]]);
        $kind = $v->enum('type_kind', MaterialKind::class, 'KATEGORI BAHAN');

        $attributes = array_filter([
            'base_uom' => $v->enum('base_uom', BaseUom::class, 'SATUAN DASAR', ['meter' => BaseUom::M, 'gram' => BaseUom::Gr])?->value,
            'pack_uom' => $v->text('pack_uom') ?: null,
            'qty_per_pack' => $v->number('qty_per_pack', 'ISI PER SATUAN BELI', min: 0.001),
            'min_stock' => $v->number('min_stock', 'MIN STOK', min: 0),
            'is_active' => $v->bool('is_active'),
        ], fn ($value): bool => $value !== null);

        $openingQty = $v->number('opening_qty', 'STOK AWAL', min: 0.001);
        $openingCost = $v->number('opening_cost', 'HARGA PER SATUAN DASAR', min: 0);
        $openingDate = $v->date('opening_date', 'TGL STOK AWAL') ?? today()->toDateString();
        $v->throwIfInvalid();

        $warehouse = $openingQty !== null ? $this->warehouse($v->text('warehouse')) : null;
        $uom = isset($attributes['base_uom']) ? BaseUom::from($attributes['base_uom']) : null;
        $type = $this->materialType($v->text('type_code'), $v->text('type_name'), $kind, $uom);
        $color = $this->color($v->text('color_code'), $v->text('color_name'));

        $code = $v->text('code');
        $material = match (true) {
            $v->filled('material_id') => Material::query()->find((int) $v->text('material_id'))
                ?? throw new BusinessRuleException("ID BAHAN {$v->text('material_id')} tidak ditemukan; kosongkan ID untuk bahan baru"),
            $code !== '' => $this->findCi(Material::query(), 'code', $code),
            default => Material::query()->where('material_type_id', $type->id)->where('color_id', $color?->id)->first(),
        };

        if ($material === null) {
            $material = $this->createMaterial($type, $color, $code !== '' ? $code : null, $attributes);
        } else {
            $this->warnIfChangedSinceExport($material, $v, "bahan {$material->code}");

            // Ganti kode bahan lewat ID BAHAN.
            if ($code !== '' && Str::lower($code) !== Str::lower($material->code)) {
                $other = $this->findCi(Material::query(), 'code', $code);

                if ($other !== null && $other->id !== $material->id) {
                    throw new BusinessRuleException("kode bahan {$code} sudah dipakai bahan lain");
                }

                $material->code = $code;
            }

            $material->fill(['material_type_id' => $type->id, ...$attributes]);
            if ($color !== null) {
                $material->color_id = $color->id;
            }
            $this->saveIfDirty($material, 'Bahan');
        }

        if ($openingQty === null) {
            return;
        }

        $hasOpening = $material->movements()
            ->where('warehouse_id', $warehouse->id)
            ->where('movement_type', MaterialMovementType::Opening)
            ->exists();

        if ($hasOpening) {
            $this->bump('Stok Awal Bahan', 'skipped');

            return;
        }

        $this->inventory->openingMaterial($material->id, $warehouse->id, $openingQty, $openingCost, $openingDate, self::NOTE);
        $this->bump('Stok Awal Bahan', 'created');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function importProduct(array $input): void
    {
        $v = $this->reader($input);

        $modelCode = $v->text('model_code');
        $sku = $v->text('sku');
        $v->requireText(['model_code' => 'KODE MODEL']);
        $v->maxLength(['model_code' => ['KODE MODEL', 30], 'model_name' => ['NAMA MODEL', 150], 'sku' => ['SKU VARIAN', 40], 'name' => ['NAMA VARIAN', 200]]);

        $modelKind = $v->enum('model_kind', ProductKind::class, 'JENIS PRODUK');
        $modelMargin = $v->percent('model_margin', 'MARGIN MODEL %');
        $margin = $v->percent('margin', 'MARGIN VARIAN %');
        $minStock = $v->number('min_stock', 'MIN STOK', min: 0, integer: true);
        $packingCost = $v->number('packing_cost', 'BIAYA PACKING', min: 0);
        $isActive = $v->bool('is_active');
        $v->throwIfInvalid();

        $category = $v->text('category') !== '' ? $this->category($v->text('category')) : null;
        $color = $this->color($v->text('color_code'), $v->text('color_name'));
        $size = $v->text('size') !== '' ? $this->size($v->text('size')) : null;

        $modelAttributes = array_filter([
            'name' => $v->text('model_name') ?: null,
            'category_id' => $category?->id,
            'kind' => $modelKind?->value,
            'default_margin_pct' => $modelMargin,
        ], fn ($value): bool => $value !== null);

        $model = $this->findCi(ProductModel::withTrashed(), 'code', $modelCode);

        if ($model?->trashed()) {
            throw new BusinessRuleException("model {$modelCode} sudah dihapus.");
        }

        if ($model === null) {
            $model = $this->createModel($modelCode, [
                ...$modelAttributes,
                'name' => $modelAttributes['name'] ?? $this->modelNameFromVariant($v->text('name'), $color, $size),
            ]);
        } else {
            $model->fill($modelAttributes);
            $modelMarginChanged = $model->isDirty(['default_margin_pct', 'kind']);
            $this->saveIfDirty($model, 'Model');

            if ($modelMarginChanged) {
                $this->triggerPrice($model->variants()->pluck('id'), today()->toDateString());
            }
        }

        // SKU kosong: varian baru = rumus kode model + kode warna + ukuran; varian lama (ID VARIAN) = SKU tidak diubah.
        if ($sku === '' && ! $v->filled('variant_id')) {
            $sku = ProductVariant::skuFor($model->code, $color?->code, $size?->code);
        }

        $variant = $v->filled('variant_id')
            ? ProductVariant::withTrashed()->find((int) $v->text('variant_id'))
                ?? throw new BusinessRuleException("ID VARIAN {$v->text('variant_id')} tidak ditemukan; kosongkan ID untuk varian baru")
            : $this->findCi(ProductVariant::withTrashed(), 'sku', $sku);

        $sku = $sku === '' ? $variant->sku : $sku;

        if ($variant?->trashed()) {
            throw new BusinessRuleException("SKU {$variant->sku} sudah dihapus.");
        }

        if ($variant !== null) {
            $this->warnIfChangedSinceExport($variant, $v, "SKU {$variant->sku}");

            // Ganti SKU lewat ID VARIAN.
            if (Str::lower($sku) !== Str::lower($variant->sku)) {
                $other = $this->findCi(ProductVariant::withTrashed(), 'sku', $sku);

                if ($other !== null && $other->id !== $variant->id) {
                    throw new BusinessRuleException("SKU {$sku} sudah dipakai varian lain");
                }

                $this->pendingWarnings[] = "SKU {$variant->sku} diganti menjadi {$sku}; perbarui juga SKU di marketplace/BigSeller atau tambahkan SKU lama ke kolom SKU BIG SELLER.";
                $variant->sku = $sku;
            }
        }

        $variantAttributes = array_filter([
            'name' => $v->text('name') ?: null,
            'color_id' => $color?->id,
            'size_id' => $size?->id,
            'min_stock' => $minStock === null ? null : (int) $minStock,
            'margin_pct' => $margin,
            'packing_cost' => $packingCost,
            'is_active' => $isActive,
        ], fn ($value): bool => $value !== null);

        if ($variant === null) {
            $variant = ProductVariant::create([
                'model_id' => $model->id,
                'sku' => $sku,
                'name' => Str::limit(trim(implode(' ', array_filter([$model->name, $color?->name, $size?->code]))), 200, ''),
                ...$variantAttributes,
            ]);
            $this->bump('Varian', 'created');
            $this->triggerPrice([$variant->id], null);
        } else {
            $variant->fill(['model_id' => $model->id, ...$variantAttributes]);

            // Harga otomatis: margin berubah = berlaku hari ini; model/warna/ukuran berubah = dari tanggal resep.
            if ($variant->isDirty(['margin_pct', 'packing_cost'])) {
                $this->triggerPrice([$variant->id], today()->toDateString());
            } elseif ($variant->isDirty(['model_id', 'color_id', 'size_id'])) {
                $this->triggerPrice([$variant->id], null);
            }

            $this->saveIfDirty($variant, 'Varian');
        }

        foreach (preg_split('/[,;\n]+/', $v->text('aliases')) ?: [] as $alias) {
            $alias = trim($alias);

            if ($alias === '') {
                continue;
            }

            $existing = $this->findCi(SkuAlias::query(), 'alias', $alias);

            if ($existing === null) {
                $variant->skuAliases()->create(['alias' => Str::limit($alias, 120, ''), 'source' => 'bigseller']);
                $this->bump('Pemetaan SKU', 'created');
            } elseif ($existing->variant_id !== $variant->id) {
                throw new BusinessRuleException("SKU BIG SELLER \"{$alias}\" sudah dipetakan ke varian lain.");
            }
        }
    }

    /**
     * Satu resep (model + ukuran + berlaku mulai). Bila resep sudah ada, komponennya diganti isi file.
     *
     * @param  Collection<int, array{sheet: string, line: int, values: array<string, mixed>}>  $rows
     */
    private function importBom(Collection $rows): void
    {
        $first = $this->reader($rows->first()['values']);
        $modelCode = $first->text('model_code');
        $first->requireText(['model_code' => 'KODE MODEL']);
        $effectiveFrom = $first->date('effective_from', 'BERLAKU MULAI', required: true);
        $first->throwIfInvalid();

        $model = $this->findCi(ProductModel::withTrashed(), 'code', $modelCode)
            ?? $this->createModel($modelCode, []);

        if ($model->trashed()) {
            throw new BusinessRuleException("model {$modelCode} sudah dihapus.");
        }

        $size = $first->text('size') !== '' ? $this->size($first->text('size')) : null;

        $lines = [];
        $lineErrors = [];

        foreach ($rows as $row) {
            $v = $this->reader($row['values']);
            $type = $v->enum('line_type', BomLineType::class, 'JENIS BARIS', ['ongkos' => BomLineType::Labor], required: true);
            $qty = $v->number('qty', 'PEMAKAIAN PER PCS', min: 0.0001) ?? 1;

            try {
                if ($type === BomLineType::Labor) {
                    $v->requireText(['labor_code' => 'KODE ONGKOS']);
                    $rate = $v->number('labor_rate', 'ONGKOS PER PCS', min: 0, required: true);
                    $v->throwIfInvalid();

                    $lines[] = [
                        'line_type' => BomLineType::Labor,
                        'labor_type_id' => $this->laborType($v->text('labor_code'), $v->text('labor_name'))->id,
                        'labor_rate' => $rate,
                        'qty_per_unit' => $qty,
                    ];
                } elseif ($type === BomLineType::Material) {
                    $v->requireText(['type_code' => 'KODE JENIS BAHAN']);
                    $v->throwIfInvalid();

                    $materialType = $this->materialType($v->text('type_code'), '', null, null);
                    $material = null;

                    if ($v->text('material_code') !== '') {
                        $material = $this->materialByCode($v->text('material_code'), $materialType);

                        if ($material->material_type_id !== $materialType->id) {
                            throw new BusinessRuleException("bahan {$material->code} bukan jenis {$materialType->code}");
                        }
                    }

                    $lines[] = [
                        'line_type' => BomLineType::Material,
                        'material_type_id' => $materialType->id,
                        'color_mode' => $material ? BomColorMode::Fixed : BomColorMode::FollowVariant,
                        'material_id' => $material?->id,
                        'qty_per_unit' => $qty,
                    ];
                } else {
                    $v->throwIfInvalid();
                }
            } catch (BusinessRuleException $exception) {
                $lineErrors[] = $this->linePrefix($rows, $row).rtrim($exception->getMessage(), '.');
            }
        }

        if ($lineErrors !== []) {
            throw new BusinessRuleException(implode('; ', $lineErrors).'.');
        }

        $bom = Bom::query()
            ->with('items')
            ->where('model_id', $model->id)
            ->where('size_id', $size?->id)
            ->whereDate('effective_from', $effectiveFrom)
            ->first();
        $note = $first->text('note') ?: null;

        if ($bom === null) {
            $bom = Bom::create(['model_id' => $model->id, 'size_id' => $size?->id, 'effective_from' => $effectiveFrom, 'note' => $note]);
            $this->bump('Resep (BOM)', 'created');
        } else {
            $sameItems = $this->bomSignature($bom->items->map(fn (BomItem $item): array => $item->only(array_keys(self::BOM_LINE_DEFAULTS))))
                === $this->bomSignature($lines);

            if ($sameItems && ($note === null || $note === $bom->note)) {
                return;
            }

            $bom->items()->delete();
            $bom->update(['note' => $note ?? $bom->note]);
            $this->bump('Resep (BOM)', 'updated');
        }

        $bom->items()->createMany($lines);

        foreach (app(CostingService::class)->wageWarnings($bom->refresh()) as $warning) {
            $this->warn($warning);
        }

        $this->triggerPrice(
            ProductVariant::query()->where('model_id', $model->id)->when($size, fn ($query) => $query->where('size_id', $size->id))->pluck('id'),
            $effectiveFrom,
        );
    }

    /**
     * Sidik komponen resep (urutan diabaikan) untuk mendeteksi resep yang tidak berubah.
     *
     * @param  iterable<array<string, mixed>>  $lines
     */
    private function bomSignature(iterable $lines): string
    {
        return collect($lines)
            ->map(function (array $line): string {
                $line = [...self::BOM_LINE_DEFAULTS, ...$line];

                return implode(':', [
                    $line['line_type'] instanceof BackedEnum ? $line['line_type']->value : $line['line_type'],
                    $line['material_type_id'],
                    $line['color_mode'] instanceof BackedEnum ? $line['color_mode']->value : $line['color_mode'],
                    $line['material_id'],
                    number_format((float) $line['qty_per_unit'], 4, '.', ''),
                    $line['labor_type_id'],
                    $line['labor_rate'] === null ? '' : number_format((float) $line['labor_rate'], 2, '.', ''),
                ]);
            })
            ->sort()
            ->implode('|');
    }

    /**
     * Awalan "baris N: " hanya bila dokumen terdiri dari lebih dari 1 baris.
     *
     * @param  Collection<int, array{line: int}>  $rows
     * @param  array{line: int}  $row
     */
    private function linePrefix(Collection $rows, array $row): string
    {
        return $rows->count() > 1 ? "baris {$row['line']}: " : '';
    }

    /**
     * Satu nota pembelian. Nota yang sama persis (tanggal, supplier, gudang, no ref, item) dilewati agar stok tidak dobel.
     *
     * @param  Collection<int, array{sheet: string, line: int, values: array<string, mixed>}>  $rows
     */
    private function importPurchase(Collection $rows): void
    {
        $first = $this->reader($rows->first()['values']);
        $date = $first->date('date', 'TANGGAL', required: true);
        $first->maxLength(['supplier' => ['SUPPLIER', 120], 'ref_no' => ['NO REF', 60]]);
        $first->throwIfInvalid();

        $warehouse = $this->warehouse($first->text('warehouse'));
        $items = [];
        $lineErrors = [];

        foreach ($rows as $row) {
            $v = $this->reader($row['values']);
            $qtyPack = $v->number('qty_pack', 'QTY BELI', min: 0.001, required: true);
            $qtyBase = $v->number('qty_base', 'QTY SATUAN DASAR', min: 0.001);
            $total = $v->number('total_price', 'TOTAL HARGA', min: 0, required: true);

            try {
                $v->requireText(['material_code' => 'KODE BAHAN']);
                $v->throwIfInvalid();
                $material = $this->materialByCode($v->text('material_code'));

                $items[] = [
                    'material_id' => $material->id,
                    'qty_pack' => $qtyPack,
                    'qty_base' => $qtyBase ?? round($qtyPack * (float) $material->qty_per_pack, 3),
                    'total_price' => $total,
                ];
            } catch (BusinessRuleException $exception) {
                $lineErrors[] = $this->linePrefix($rows, $row).rtrim($exception->getMessage(), '.');
            }
        }

        if ($lineErrors !== []) {
            throw new BusinessRuleException(implode('; ', $lineErrors).'.');
        }

        $supplierName = $first->text('supplier');
        $supplier = $supplierName !== '' ? $this->findCi(Supplier::query(), 'name', $supplierName) : null;
        $refNo = $first->text('ref_no') ?: null;

        if ($this->purchaseExists($date, $warehouse->id, $supplier?->id, $refNo, $items, $supplierName !== '' && $supplier === null)) {
            $this->bump('Pembelian Bahan', 'skipped');

            return;
        }

        if ($supplierName !== '' && $supplier === null) {
            $supplier = Supplier::create(['name' => $supplierName, 'phone' => $first->text('supplier_phone') ?: null]);
            $this->bump('Supplier', 'created');
        }

        $this->inventory->createMaterialPurchase([
            'supplier_id' => $supplier?->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => $date,
            'ref_no' => $refNo,
            'note' => $first->text('note') ?: self::NOTE,
        ], $items);
        $this->bump('Pembelian Bahan', 'created');

        $this->triggerPrice(
            collect($this->pricing->variantsUsingMaterials(array_column($items, 'material_id')))->pluck('id'),
            $date,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function purchaseExists(string $date, int $warehouseId, ?int $supplierId, ?string $refNo, array $items, bool $newSupplier): bool
    {
        if ($newSupplier) {
            return false;
        }

        $signature = fn (iterable $lines): string => collect($lines)
            ->map(fn ($line): string => sprintf('%d:%.3f:%.2f', $line['material_id'], $line['qty_base'], $line['total_price']))
            ->sort()
            ->implode('|');

        $expected = $signature($items);

        return MaterialPurchase::query()
            ->with('items')
            ->whereDate('purchase_date', $date)
            ->where('warehouse_id', $warehouseId)
            ->where('supplier_id', $supplierId)
            ->where('ref_no', $refNo)
            ->get()
            ->contains(fn (MaterialPurchase $purchase): bool => $signature($purchase->items->map(fn ($item): array => [
                'material_id' => $item->material_id,
                'qty_base' => (float) $item->qty_base,
                'total_price' => (float) $item->total_price,
            ])) === $expected);
    }

    /**
     * Catat varian yang HPP & harganya perlu dihitung ulang setelah impor (null = tanggal resep yang berlaku).
     *
     * @param  iterable<int>  $variantIds
     */
    private function triggerPrice(iterable $variantIds, ?string $date): void
    {
        foreach ($variantIds as $variantId) {
            $this->pendingTriggers[$variantId.'|'.$date] = ['variant_id' => (int) $variantId, 'date' => $date];
        }
    }

    /**
     * HARGA OTOMATIS: hitung HPP dari resep & harga reseller (HPP x (1 + margin)) untuk varian yang terdampak impor.
     */
    private function syncPrices(): void
    {
        $result = ['synced' => 0, 'skipped' => []];

        collect($this->priceTriggers)
            ->groupBy('date')
            ->sortKeys()
            ->each(function ($triggers, $date) use (&$result): void {
                $variants = ProductVariant::query()->whereIn('id', $triggers->pluck('variant_id'))->get();
                $synced = $this->pricing->syncVariants($variants, $date === '' ? null : $date);

                $result['synced'] += $synced['synced'];
                $result['skipped'] = [...$result['skipped'], ...$synced['skipped']];
            });

        if ($result['synced'] > 0) {
            $this->stats['Harga Reseller (otomatis)'] = ['created' => $result['synced'], 'updated' => 0, 'skipped' => 0];
        }

        foreach (array_unique($result['skipped']) as $skipped) {
            $this->warnings[] = "Harga otomatis belum dibuat — {$skipped}";
        }
    }

    /**
     * Jenis bahan; bila belum ada dibuat. Kategori kosong ditebak dari satuan (pcs/gr = aksesoris, lainnya kain).
     */
    private function materialType(string $code, string $name, ?MaterialKind $kind, ?BaseUom $uom): MaterialType
    {
        if ($code === '') {
            throw new BusinessRuleException('KODE JENIS BAHAN kosong.');
        }

        if ($type = $this->findCi(MaterialType::query(), 'code', $code)) {
            return $type;
        }

        if ($kind === null) {
            $kind = in_array($uom, [BaseUom::Pcs, BaseUom::Gr], true) ? MaterialKind::Aksesoris : MaterialKind::Kain;
            $this->warn("jenis bahan {$code} dibuat dengan kategori {$kind->getLabel()} (tebakan".($uom ? " dari satuan {$uom->value}" : '').').');
        }

        $this->bump('Jenis Bahan', 'created');

        return MaterialType::create(['code' => Str::upper(Str::limit($code, 30, '')), 'name' => Str::limit($name ?: $code, 80, ''), 'kind' => $kind]);
    }

    /**
     * Warna; bila belum ada dibuat. Kode kosong dibuat dari nama ("Biru Muda" => BIRUMUDA).
     * Nama didahulukan (unik & bermakna) agar hasil sama di setiap impor ulang.
     */
    private function color(string $code, string $name): ?Color
    {
        if ($code === '' && $name === '') {
            return null;
        }

        $color = ($name !== '' ? $this->findCi(Color::query(), 'name', $name) : null)
            ?? ($code !== '' ? $this->findCi(Color::query(), 'code', $code) : null);

        if ($color !== null) {
            return $color;
        }

        $this->bump('Warna', 'created');

        return Color::create([
            'code' => $code !== '' ? Str::upper(Str::limit($code, 10, '')) : $this->uniqueCode(Color::class, $name, 10, ''),
            'name' => Str::limit($name ?: $code, 60, ''),
        ]);
    }

    /**
     * Model baru. Nama kosong = kode; kategori kosong = "Umum".
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createModel(string $code, array $attributes): ProductModel
    {
        $defaults = [];

        if (blank($attributes['name'] ?? null)) {
            $attributes['name'] = $code;
            $defaults[] = "nama \"{$code}\"";
        }

        if (blank($attributes['category_id'] ?? null)) {
            $attributes['category_id'] = $this->category('Umum')->id;
            $defaults[] = 'kategori Umum';
        }

        if ($defaults !== []) {
            $this->warn("model {$code} dibuat otomatis dengan ".implode(' dan ', $defaults).'.');
        }

        $this->bump('Model', 'created');

        return ProductModel::create(['code' => $code, ...$attributes]);
    }

    /**
     * Nama model dari NAMA VARIAN tanpa ukuran & warna di belakangnya ("Baju Chef 01 Hitam S" => "Baju Chef 01").
     */
    private function modelNameFromVariant(string $variantName, ?Color $color, ?Size $size): ?string
    {
        $name = trim($variantName);

        foreach ([$size?->code, $color?->name] as $part) {
            if ($part && Str::endsWith(Str::lower($name), ' '.Str::lower($part))) {
                $name = trim(Str::substr($name, 0, Str::length($name) - Str::length($part) - 1));
            }
        }

        return $name !== '' ? Str::limit($name, 150, '') : null;
    }

    /**
     * Bahan baru. Satuan kosong ditebak dari kategori jenis bahan (kain = cm/roll, aksesoris = pcs), isi per satuan beli = 1.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createMaterial(MaterialType $type, ?Color $color, ?string $code, array $attributes): Material
    {
        $code ??= Str::upper($type->code.($color ? ' - '.$color->name : ''));
        $isFabric = $type->kind === MaterialKind::Kain;
        $defaults = [];

        if (! isset($attributes['base_uom'])) {
            $attributes['base_uom'] = $isFabric ? BaseUom::Cm->value : BaseUom::Pcs->value;
            $defaults[] = "satuan {$attributes['base_uom']}";
        }

        if (! isset($attributes['qty_per_pack'])) {
            $attributes['qty_per_pack'] = 1;
            $defaults[] = 'isi per satuan beli 1';
        }

        if ($defaults !== []) {
            $this->warn("bahan {$code} dibuat dengan ".implode(', ', $defaults).' — cek satuan karena memengaruhi stok & HPP.');
        }

        $this->bump('Bahan', 'created');

        return Material::create([
            'material_type_id' => $type->id,
            'color_id' => $color?->id,
            'code' => Str::limit($code, 60, ''),
            'pack_uom' => $isFabric ? 'roll' : 'pcs',
            ...$attributes,
        ]);
    }

    /**
     * Bahan dari kode (sheet Resep/Pembelian). Bila belum ada dibuat: jenis & warna dari kode "JENIS - WARNA".
     */
    private function materialByCode(string $code, ?MaterialType $type = null): Material
    {
        if ($material = $this->findCi(Material::query(), 'code', $code)) {
            return $material;
        }

        [$typeCode, $colorName] = array_map('trim', explode(' - ', $code, 2) + [1 => '']);
        $type ??= $this->materialType($typeCode, '', null, null);
        $color = $colorName !== '' ? $this->color('', $colorName) : null;

        $existing = Material::query()->where('material_type_id', $type->id)->where('color_id', $color?->id)->first();

        if ($existing !== null) {
            $this->warn("kode bahan \"{$code}\" dianggap sama dengan bahan {$existing->code} (jenis & warna sama).");

            return $existing;
        }

        return $this->createMaterial($type, $color, $code, []);
    }

    /**
     * Kode unik dari teks bebas untuk tabel bantu.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueCode(string $model, string $text, int $max, string $separator = '-'): string
    {
        $base = Str::upper(Str::slug($text, $separator)) ?: 'X';
        $candidate = Str::limit($base, $max, '');

        for ($i = 2; $this->findCi($model::query(), 'code', $candidate) !== null; $i++) {
            $candidate = Str::limit($base, $max - strlen((string) $i), '').$i;
        }

        return $candidate;
    }

    private function warn(string $message): void
    {
        $this->pendingWarnings[] = $message;
    }

    private function size(string $code): Size
    {
        if ($size = $this->findCi(Size::query(), 'code', $code)) {
            return $size;
        }

        $this->bump('Ukuran', 'created');

        return Size::create(['code' => Str::upper(Str::limit($code, 15, '')), 'sort_order' => (int) Size::query()->max('sort_order') + 1]);
    }

    private function category(string $name): ProductCategory
    {
        if ($category = $this->findCi(ProductCategory::query(), 'name', $name)) {
            return $category;
        }

        $this->bump('Kategori', 'created');

        return ProductCategory::create(['name' => Str::limit($name, 60, '')]);
    }

    private function laborType(string $code, string $name): LaborType
    {
        if ($type = $this->findCi(LaborType::query(), 'code', $code)) {
            return $type;
        }

        $this->bump('Jenis Ongkos', 'created');
        $wageType = LaborType::guessWageType($code, $name);
        $this->warn("jenis ongkos {$code} dibuat otomatis, dibayar sebagai: ".($wageType?->getLabel() ?? 'Biaya Operasional (bukan upah SPK)').' — cek di menu Jenis Ongkos Kerja.');

        return LaborType::create(['code' => Str::upper(Str::limit($code, 20, '')), 'name' => Str::limit($name ?: $code, 60, ''), 'wage_type' => $wageType]);
    }

    /**
     * Gudang BAHAN (stok awal & pembelian bahan) dari kode/nama; bila belum ada dibuat dengan jenis bahan.
     * Kosong = gudang default bahan (dibuat "Gudang Bahan Baku" bila belum ada sama sekali).
     */
    private function warehouse(string $value): Warehouse
    {
        if ($value === '') {
            $warehouse = Warehouse::defaultForMaterials();

            if ($warehouse !== null) {
                return $warehouse;
            }

            $value = 'Gudang Bahan Baku';
        }

        $warehouse = $this->findCi(Warehouse::query(), 'code', $value) ?? $this->findCi(Warehouse::query(), 'name', $value);

        if ($warehouse !== null) {
            return $warehouse;
        }

        $warehouse = Warehouse::create([
            'code' => $this->uniqueCode(Warehouse::class, $value, 20),
            'name' => Str::limit($value, 80, ''),
            'kind' => WarehouseKind::Bahan,
        ]);
        $this->warn("gudang \"{$warehouse->name}\" dibuat otomatis (kode {$warehouse->code}).");
        $this->bump('Gudang', 'created');

        return $warehouse;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function findCi(Builder $query, string $column, string $value): ?Model
    {
        return $query->whereRaw("lower({$column}) = ?", [Str::lower($value)])->first();
    }

    /**
     * Data diubah di sistem setelah file di-export (kolom DIUBAH TERAKHIR): isi file tetap dipakai + peringatan.
     */
    private function warnIfChangedSinceExport(Model $model, SpreadsheetRow $v, string $label): void
    {
        $exportedAt = $v->stamp('exported_at');

        if ($exportedAt !== null && $model->updated_at?->gt($exportedAt->addSecond())) {
            $this->warn("{$label} diubah di sistem setelah file di-export ({$model->updated_at->format('d/m/Y H:i')}); isi file dipakai.");
        }
    }

    private function saveIfDirty(Model $model, string $label): void
    {
        if ($model->isDirty()) {
            $model->save();
            $this->bump($label, 'updated');
        }
    }

    private function bump(string $label, string $kind): void
    {
        $this->pending[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->pending[$label][$kind]++;
    }

    /**
     * Jalankan 1 baris/dokumen dalam transaksi; statistik hanya dihitung bila tersimpan.
     */
    /**
     * @param  Collection<int, array{sheet: string, line: int, values: array<string, mixed>}>  $rows  baris dokumen (untuk Import Perlu Dicek)
     */
    private function attempt(string $where, callable $callback, string $type, Collection $rows, ?string $group = null): void
    {
        $this->pending = [];
        $this->pendingWarnings = [];
        $this->pendingTriggers = [];
        $errorCount = count($this->errors);
        $issues = $this->issues[$type];

        try {
            DB::transaction($callback);

            $rows->each(fn (array $row) => $issues->fixed(ImportIssueLog::failureId($row['values']['failure_id'] ?? null)));

            foreach ($this->pending as $label => $counts) {
                $this->stats[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

                foreach ($counts as $kind => $count) {
                    $this->stats[$label][$kind] += $count;
                }
            }

            foreach ($this->pendingWarnings as $warning) {
                $this->warnings[] = "{$where}: {$warning}";
            }

            $this->priceTriggers = [...$this->priceTriggers, ...$this->pendingTriggers];
        } catch (BusinessRuleException $exception) {
            $this->errors[] = "{$where}: ".rtrim($exception->getMessage(), '.').'.';
        } catch (QueryException $exception) {
            report($exception);
            $this->errors[] = "{$where}: gagal disimpan. Periksa data atau hubungi administrator.";
        } catch (Throwable $exception) {
            report($exception);
            $this->errors[] = "{$where}: terjadi kesalahan. Hubungi administrator.";
        }

        if (count($this->errors) > $errorCount) {
            $message = Str::after(end($this->errors), ': ');
            $map = [...self::SHEETS[$type]['headers'], ...(self::SHEETS[$type]['system'] ?? [])];

            foreach ($rows as $row) {
                $issues->fail(
                    $row['line'],
                    collect($map)->map(fn (string $key) => Spreadsheet::text($row['values'][$key] ?? null))->all(),
                    $message,
                    retryOf: ImportIssueLog::failureId($row['values']['failure_id'] ?? null),
                    groupKey: $group === null ? null : $type.'|'.$issues->uploadId.'|'.$group,
                );
            }
        }

        $this->pending = [];
        $this->pendingWarnings = [];
        $this->pendingTriggers = [];
    }

    /**
     * @param  list<array{sheet: string, line: int, values: array<string, mixed>}>  $rows
     * @return Collection<string, Collection<int, array{sheet: string, line: int, values: array<string, mixed>}>>
     */
    private function groupRows(array $rows, callable $key): Collection
    {
        return collect($rows)->groupBy(fn (array $row): string => $key($row['values']));
    }

    /**
     * @param  array{sheet: string, line: int}  $row
     */
    private function where(string $type, array $row): string
    {
        return ($row['sheet'] ?: self::SHEETS[$type]['title'])." baris {$row['line']}";
    }

    /**
     * @param  Collection<int, array{sheet: string, line: int}>  $rows
     */
    private function whereGroup(string $type, Collection $rows): string
    {
        $lines = $rows->pluck('line');

        return ($rows->first()['sheet'] ?: self::SHEETS[$type]['title'])
            .' baris '.($lines->count() > 1 ? $lines->first().'-'.$lines->last() : $lines->first());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reader(array $input): SpreadsheetRow
    {
        return new SpreadsheetRow($input);
    }
}
