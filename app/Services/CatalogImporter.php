<?php

namespace App\Services;

use App\Enums\BaseUom;
use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use App\Enums\MaterialKind;
use App\Enums\MaterialMovementType;
use App\Enums\PriceSource;
use App\Enums\ProductKind;
use App\Enums\VariantCostSource;
use App\Exceptions\BusinessRuleException;
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
use App\Models\Setting;
use App\Models\Size;
use App\Models\SkuAlias;
use App\Models\Supplier;
use App\Models\VariantCost;
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
                'HPP BAHAN' => 'material_cost',
                'HPP ONGKOS' => 'labor_cost',
                'HPP BERLAKU MULAI' => 'cost_from',
                'HARGA RESELLER' => 'base_price',
                'HARGA BERLAKU MULAI' => 'price_from',
            ],
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

    public function __construct(private InventoryService $inventory) {}

    /**
     * @return array{rows: int, sheets: list<string>, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>}
     */
    public function import(string $path, string $fileName): array
    {
        $this->stats = [];
        $this->errors = [];

        try {
            $sheets = Spreadsheet::readSheets($path, $fileName, collect(self::SHEETS)
                ->map(fn (array $sheet): array => ['headers' => $sheet['headers'], 'required' => $sheet['required']])
                ->all());
        } catch (BusinessRuleException $exception) {
            return ['rows' => 0, 'sheets' => [], 'stats' => [], 'errors' => [$exception->getMessage()]];
        }

        if ($sheets === []) {
            return ['rows' => 0, 'sheets' => [], 'stats' => [], 'errors' => ['Tidak ada sheet yang dikenali. Gunakan template katalog (sheet Bahan / Produk / Resep BOM / Pembelian Bahan).']];
        }

        foreach ($sheets['materials'] ?? [] as $row) {
            $this->attempt($this->where('materials', $row), fn () => $this->importMaterial($row['values']));
        }

        foreach ($sheets['products'] ?? [] as $row) {
            $this->attempt($this->where('products', $row), fn () => $this->importProduct($row['values']));
        }

        $this->groupRows($sheets['boms'] ?? [], fn (array $values): string => implode('|', [
            Str::lower(Spreadsheet::text($values['model_code'] ?? null)),
            Str::lower(Spreadsheet::text($values['size'] ?? null)),
            Spreadsheet::parseDate($values['effective_from'] ?? null) ?? Spreadsheet::text($values['effective_from'] ?? null),
        ]))->each(fn (Collection $rows) => $this->attempt($this->whereGroup('boms', $rows), fn () => $this->importBom($rows)));

        $this->groupRows($sheets['purchases'] ?? [], fn (array $values): string => implode('|', [
            Spreadsheet::parseDate($values['date'] ?? null) ?? Spreadsheet::text($values['date'] ?? null),
            Str::lower(Spreadsheet::text($values['supplier'] ?? null)),
            Str::lower(Spreadsheet::text($values['ref_no'] ?? null)),
            Str::lower(Spreadsheet::text($values['warehouse'] ?? null)),
        ]))->each(fn (Collection $rows) => $this->attempt($this->whereGroup('purchases', $rows), fn () => $this->importPurchase($rows)));

        return [
            'rows' => collect($sheets)->sum(fn (array $rows): int => count($rows)),
            'sheets' => collect(array_keys($sheets))->map(fn (string $key): string => self::SHEETS[$key]['title'])->all(),
            'stats' => $this->stats,
            'errors' => $this->errors,
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
        $type = $this->materialType($v->text('type_code'), $v->text('type_name'), $kind);
        $color = $this->color($v->text('color_code'), $v->text('color_name'));

        $code = $v->text('code');
        $material = $code !== ''
            ? $this->findCi(Material::query(), 'code', $code)
            : Material::query()->where('material_type_id', $type->id)->where('color_id', $color?->id)->first();

        if ($material === null) {
            foreach (['base_uom' => 'SATUAN DASAR', 'qty_per_pack' => 'ISI PER SATUAN BELI'] as $key => $label) {
                if (! isset($attributes[$key])) {
                    throw new BusinessRuleException("{$label} wajib diisi untuk bahan baru.");
                }
            }

            $material = Material::create([
                'material_type_id' => $type->id,
                'color_id' => $color?->id,
                'code' => $code !== '' ? $code : Str::upper($type->code.($color ? ' - '.$color->name : '')),
                'pack_uom' => 'roll',
                ...$attributes,
            ]);
            $this->bump('Bahan', 'created');
        } else {
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
        $v->requireText(['model_code' => 'KODE MODEL', 'sku' => 'SKU VARIAN']);
        $v->maxLength(['model_code' => ['KODE MODEL', 30], 'model_name' => ['NAMA MODEL', 150], 'sku' => ['SKU VARIAN', 40], 'name' => ['NAMA VARIAN', 200]]);

        $modelKind = $v->enum('model_kind', ProductKind::class, 'JENIS PRODUK');
        $modelMargin = $v->percent('model_margin', 'MARGIN MODEL %');
        $margin = $v->percent('margin', 'MARGIN VARIAN %');
        $minStock = $v->number('min_stock', 'MIN STOK', min: 0, integer: true);
        $isActive = $v->bool('is_active');
        $materialCost = $v->number('material_cost', 'HPP BAHAN', min: 0);
        $laborCost = $v->number('labor_cost', 'HPP ONGKOS', min: 0);
        $basePrice = $v->number('base_price', 'HARGA RESELLER', min: 0);
        $costFrom = $v->date('cost_from', 'HPP BERLAKU MULAI', required: $materialCost !== null || $laborCost !== null);
        $priceFrom = $v->date('price_from', 'HARGA BERLAKU MULAI', required: $basePrice !== null);
        $v->throwIfInvalid();

        $category = $v->text('category') !== '' ? $this->category($v->text('category')) : null;
        $model = $this->findCi(ProductModel::withTrashed(), 'code', $modelCode);

        if ($model?->trashed()) {
            throw new BusinessRuleException("model {$modelCode} sudah dihapus.");
        }

        $modelAttributes = array_filter([
            'name' => $v->text('model_name') ?: null,
            'category_id' => $category?->id,
            'kind' => $modelKind?->value,
            'default_margin_pct' => $modelMargin,
        ], fn ($value): bool => $value !== null);

        if ($model === null) {
            if (! isset($modelAttributes['name'], $modelAttributes['category_id'])) {
                throw new BusinessRuleException("model {$modelCode} belum terdaftar, NAMA MODEL dan KATEGORI wajib diisi.");
            }

            $model = ProductModel::create(['code' => $modelCode, ...$modelAttributes]);
            $this->bump('Model', 'created');
        } else {
            $model->fill($modelAttributes);
            $this->saveIfDirty($model, 'Model');
        }

        $color = $this->color($v->text('color_code'), $v->text('color_name'));
        $size = $v->text('size') !== '' ? $this->size($v->text('size')) : null;
        $variant = $this->findCi(ProductVariant::withTrashed(), 'sku', $sku);

        if ($variant?->trashed()) {
            throw new BusinessRuleException("SKU {$sku} sudah dihapus.");
        }

        $variantAttributes = array_filter([
            'name' => $v->text('name') ?: null,
            'color_id' => $color?->id,
            'size_id' => $size?->id,
            'min_stock' => $minStock === null ? null : (int) $minStock,
            'margin_pct' => $margin,
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
        } else {
            $variant->fill(['model_id' => $model->id, ...$variantAttributes]);
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

        $cost = null;

        if ($costFrom !== null) {
            $cost = $variant->costs()->whereDate('effective_from', $costFrom)->first();
            $values = ['material_cost' => $materialCost ?? 0, 'labor_cost' => $laborCost ?? 0];

            if ($cost === null) {
                $cost = $variant->costs()->create([...$values, 'effective_from' => $costFrom, 'source' => VariantCostSource::Legacy]);
                $this->bump('HPP', 'created');
            } elseif ((float) $cost->material_cost !== (float) $values['material_cost'] || (float) $cost->labor_cost !== (float) $values['labor_cost']) {
                $cost->update([...$values, 'source' => VariantCostSource::Legacy]);
                $this->bump('HPP', 'updated');
            }
        }

        if ($priceFrom !== null) {
            $price = $variant->priceHistories()->whereDate('effective_from', $priceFrom)->first();

            if ($price === null) {
                $variant->priceHistories()->create([
                    'effective_from' => $priceFrom,
                    'base_price' => $basePrice,
                    'variant_cost_id' => $cost?->id ?? $this->costAt($variant, $priceFrom)?->id,
                    'source' => PriceSource::Import,
                    'approved_by' => auth()->id(),
                    'note' => self::NOTE,
                ]);
                $this->bump('Harga Reseller', 'created');
            } elseif ((float) $price->base_price !== $basePrice) {
                $price->update(['base_price' => $basePrice, 'source' => PriceSource::Import, 'approved_by' => auth()->id()]);
                $this->bump('Harga Reseller', 'updated');
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

        $model = $this->findCi(ProductModel::query(), 'code', $modelCode)
            ?? throw new BusinessRuleException("model {$modelCode} tidak ditemukan.");
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

                    $materialType = $this->findCi(MaterialType::query(), 'code', $v->text('type_code'))
                        ?? throw new BusinessRuleException("jenis bahan {$v->text('type_code')} tidak ditemukan");
                    $material = null;

                    if ($v->text('material_code') !== '') {
                        $material = $this->findCi(Material::query(), 'code', $v->text('material_code'))
                            ?? throw new BusinessRuleException("bahan {$v->text('material_code')} tidak ditemukan");

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
                $v->throwIfInvalid();
                $material = $this->findCi(Material::query(), 'code', $v->text('material_code'))
                    ?? throw new BusinessRuleException("bahan {$v->text('material_code')} tidak ditemukan");

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

    private function costAt(ProductVariant $variant, string $date): ?VariantCost
    {
        return $variant->costs()->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    private function materialType(string $code, string $name, ?MaterialKind $kind): MaterialType
    {
        if ($code === '') {
            throw new BusinessRuleException('KODE JENIS BAHAN kosong.');
        }

        if ($type = $this->findCi(MaterialType::query(), 'code', $code)) {
            return $type;
        }

        if ($kind === null) {
            throw new BusinessRuleException("jenis bahan {$code} belum terdaftar, KATEGORI BAHAN (kain/aksesoris) wajib diisi.");
        }

        $this->bump('Jenis Bahan', 'created');

        return MaterialType::create(['code' => Str::upper(Str::limit($code, 30, '')), 'name' => Str::limit($name ?: $code, 80, ''), 'kind' => $kind]);
    }

    private function color(string $code, string $name): ?Color
    {
        if ($code === '' && $name === '') {
            return null;
        }

        $color = ($code !== '' ? $this->findCi(Color::query(), 'code', $code) : null)
            ?? ($name !== '' ? $this->findCi(Color::query(), 'name', $name) : null);

        if ($color !== null) {
            return $color;
        }

        if ($code === '') {
            throw new BusinessRuleException("warna \"{$name}\" belum terdaftar, KODE WARNA wajib diisi.");
        }

        $this->bump('Warna', 'created');

        return Color::create(['code' => Str::upper(Str::limit($code, 10, '')), 'name' => Str::limit($name ?: $code, 60, '')]);
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

        return LaborType::create(['code' => Str::upper(Str::limit($code, 20, '')), 'name' => Str::limit($name ?: $code, 60, '')]);
    }

    private function warehouse(string $value): Warehouse
    {
        if ($value === '') {
            return Warehouse::query()->find(Setting::valueOf('default_warehouse_id'))
                ?? Warehouse::query()->first()
                ?? throw new BusinessRuleException('belum ada gudang; buat gudang dulu di menu Stok > Gudang.');
        }

        return $this->findCi(Warehouse::query(), 'code', $value)
            ?? $this->findCi(Warehouse::query(), 'name', $value)
            ?? throw new BusinessRuleException("gudang \"{$value}\" tidak ditemukan.");
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
    private function attempt(string $where, callable $callback): void
    {
        $this->pending = [];

        try {
            DB::transaction($callback);

            foreach ($this->pending as $label => $counts) {
                $this->stats[$label] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

                foreach ($counts as $kind => $count) {
                    $this->stats[$label][$kind] += $count;
                }
            }
        } catch (BusinessRuleException $exception) {
            $this->errors[] = "{$where}: ".rtrim($exception->getMessage(), '.').'.';
        } catch (QueryException $exception) {
            $this->errors[] = "{$where}: gagal disimpan (".Str::limit(strtok($exception->getMessage(), "\n") ?: 'kesalahan database', 160).').';
        } catch (Throwable $exception) {
            report($exception);
            $this->errors[] = "{$where}: terjadi kesalahan ({$exception->getMessage()}).";
        }

        $this->pending = [];
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
