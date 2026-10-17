<?php

namespace Database\Seeders;

use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use App\Enums\PriceSource;
use App\Enums\VariantCostSource;
use App\Models\LaborType;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\VariantCost;
use App\Models\Warehouse;
use App\Services\CostingService;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Pembelian bahan, resep (BOM), riwayat HPP, dan riwayat harga reseller.
 */
class CostingSeeder extends Seeder
{
    public function run(InventoryService $inventory, CostingService $costing): void
    {
        $warehouseId = Warehouse::query()->where('code', 'UTAMA')->value('id');
        $fabricSupplier = Supplier::query()->where('name', 'Toko Kain Sumber Rejeki')->value('id');
        $accessorySupplier = Supplier::query()->where('name', 'CV Aksesoris Jaya')->value('id');
        $material = fn (string $code): int => Material::query()->where('code', $code)->value('id');

        // Pembelian awal (Juli) dan pembelian drill dengan harga naik (September).
        $inventory->createMaterialPurchase(
            ['supplier_id' => $fabricSupplier, 'warehouse_id' => $warehouseId, 'purchase_date' => '2026-07-20', 'ref_no' => 'SR-2607-118'],
            [
                ['material_id' => $material('DRILL - HITAM'), 'qty_pack' => 6, 'qty_base' => 18000, 'total_price' => 2700000],
                ['material_id' => $material('DRILL - PUTIH'), 'qty_pack' => 4, 'qty_base' => 12000, 'total_price' => 1800000],
                ['material_id' => $material('DRILL - NAVY'), 'qty_pack' => 4, 'qty_base' => 11900, 'total_price' => 1800000],
                ['material_id' => $material('KANVAS - HITAM'), 'qty_pack' => 2, 'qty_base' => 5000, 'total_price' => 1100000],
                ['material_id' => $material('KANVAS - NAVY'), 'qty_pack' => 2, 'qty_base' => 5000, 'total_price' => 1100000],
            ],
        );
        $inventory->createMaterialPurchase(
            ['supplier_id' => $accessorySupplier, 'warehouse_id' => $warehouseId, 'purchase_date' => '2026-07-22', 'ref_no' => 'AJ-0722'],
            [
                ['material_id' => $material('KANCING CHEF'), 'qty_pack' => 15, 'qty_base' => 2160, 'total_price' => 648000],
                ['material_id' => $material('TALI WEBBING'), 'qty_pack' => 2, 'qty_base' => 20000, 'total_price' => 300000],
            ],
        );
        $inventory->createMaterialPurchase(
            ['supplier_id' => $fabricSupplier, 'warehouse_id' => $warehouseId, 'purchase_date' => '2026-09-15', 'ref_no' => 'SR-2609-201', 'note' => 'Harga drill naik'],
            [
                ['material_id' => $material('DRILL - HITAM'), 'qty_pack' => 4, 'qty_base' => 12000, 'total_price' => 2040000],
                ['material_id' => $material('DRILL - PUTIH'), 'qty_pack' => 2, 'qty_base' => 6000, 'total_price' => 1020000],
            ],
        );

        $this->seedBoms();

        foreach (ProductVariant::query()->with('model')->get() as $variant) {
            if ($variant->model->code === 'TC1') {
                VariantCost::create(['variant_id' => $variant->id, 'effective_from' => '2026-06-01', 'material_cost' => 22000, 'labor_cost' => 0, 'source' => VariantCostSource::Manual]);
                $this->price($variant, '2026-06-01', 31000, PriceSource::Import);

                continue;
            }

            // HPP LAMA dari Excel + harga reseller impor.
            $legacy = $variant->model->code === 'BC1' ? 52000 : 30000;
            VariantCost::create(['variant_id' => $variant->id, 'effective_from' => '2026-06-01', 'material_cost' => $legacy, 'labor_cost' => 0, 'source' => VariantCostSource::Legacy]);
            $this->price($variant, '2026-06-01', $variant->model->code === 'BC1' ? 70000 : 42000, PriceSource::Import);

            // HPP dari BOM per Agustus, harga baru disetujui, lalu HPP Oktober (drill naik) -> muncul usulan harga.
            $august = $costing->refreshVariantCost($variant, '2026-08-01')->fresh();
            $margin = (float) ($variant->margin_pct ?? $variant->model->default_margin_pct ?? 0.30);
            $this->price($variant, '2026-08-01', ceil((float) $august->total_cost * (1 + $margin) / 500) * 500, PriceSource::Auto, $august->id, $margin);

            $costing->refreshVariantCost($variant, '2026-10-01');
        }
    }

    private function seedBoms(): void
    {
        $type = fn (string $code): int => MaterialType::query()->where('code', $code)->value('id');
        $labor = fn (string $code): int => LaborType::query()->where('code', $code)->value('id');
        $material = fn (string $code): int => Material::query()->where('code', $code)->value('id');

        $chef = ProductModel::query()->where('code', 'BC1')->firstOrFail();
        $apron = ProductModel::query()->where('code', 'AP1')->firstOrFail();

        $chefLines = fn (int $fabricCm, int $sewingRate): array => [
            ['line_type' => BomLineType::Material, 'material_type_id' => $type('DRILL'), 'color_mode' => BomColorMode::FollowVariant, 'qty_per_unit' => $fabricCm],
            ['line_type' => BomLineType::Material, 'material_type_id' => $type('KANCING'), 'color_mode' => BomColorMode::Fixed, 'material_id' => $material('KANCING CHEF'), 'qty_per_unit' => 8],
            ['line_type' => BomLineType::Labor, 'labor_type_id' => $labor('POTONG'), 'labor_rate' => 2000, 'qty_per_unit' => 1],
            ['line_type' => BomLineType::Labor, 'labor_type_id' => $labor('JAHIT'), 'labor_rate' => $sewingRate, 'qty_per_unit' => 1],
            ['line_type' => BomLineType::Labor, 'labor_type_id' => $labor('OBRAS'), 'labor_rate' => 1500, 'qty_per_unit' => 1],
        ];

        $chef->boms()->create(['effective_from' => '2026-07-01', 'note' => 'Resep standar semua ukuran'])
            ->items()->createMany($chefLines(180, 15000));
        $chef->boms()->create(['size_id' => Size::query()->where('code', 'XL')->value('id'), 'effective_from' => '2026-07-01', 'note' => 'Ukuran XL butuh kain lebih'])
            ->items()->createMany($chefLines(210, 17000));

        $apron->boms()->create(['effective_from' => '2026-07-01', 'note' => 'Apron kanvas + tali'])
            ->items()->createMany([
                ['line_type' => BomLineType::Material, 'material_type_id' => $type('KANVAS'), 'color_mode' => BomColorMode::FollowVariant, 'qty_per_unit' => 90],
                ['line_type' => BomLineType::Material, 'material_type_id' => $type('TALI'), 'color_mode' => BomColorMode::Fixed, 'material_id' => $material('TALI WEBBING'), 'qty_per_unit' => 250],
                ['line_type' => BomLineType::Labor, 'labor_type_id' => $labor('POTONG'), 'labor_rate' => 1500, 'qty_per_unit' => 1],
                ['line_type' => BomLineType::Labor, 'labor_type_id' => $labor('JAHIT'), 'labor_rate' => 8000, 'qty_per_unit' => 1],
            ]);
    }

    private function price(ProductVariant $variant, string $from, float $basePrice, PriceSource $source, ?int $costId = null, ?float $margin = null): void
    {
        $variant->priceHistories()->create([
            'effective_from' => $from,
            'base_price' => $basePrice,
            'variant_cost_id' => $costId,
            'margin_pct' => $margin,
            'source' => $source,
            'note' => $source === PriceSource::Import ? 'Impor dari Excel HARGA RESELLER' : null,
        ]);
    }
}
