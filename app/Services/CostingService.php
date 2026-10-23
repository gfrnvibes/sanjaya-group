<?php

namespace App\Services;

use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use App\Enums\GoodsReceiptSource;
use App\Enums\MaterialMovementType;
use App\Enums\ProductKind;
use App\Enums\VariantCostSource;
use App\Enums\WageType;
use App\Exceptions\BusinessRuleException;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\GoodsReceiptItem;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\MaterialPurchaseItem;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\VariantCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HPP varian:
 *  - PRODUKSI: resep (BOM) x harga beli bahan terakhir + ongkos + packing;
 *  - BELI JADI: harga beli terakhir (Barang Masuk sumber Beli Jadi) + biaya packing.
 */
class CostingService
{
    /**
     * Kode bawaan jenis ongkos upah jahit / potong (dipakai migrasi data lama). Perhitungan upah memakai
     * "Dibayar sebagai" (labor_types.wage_type), bukan kode.
     */
    public const SEWING_LABOR_CODE = 'JAHIT';

    public const CUTTING_LABOR_CODE = 'POTONG';

    /**
     * Resep berlaku untuk varian pada tanggal tertentu; resep khusus ukuran didahulukan.
     */
    public function bomFor(ProductVariant $variant, CarbonInterface|string $date): ?Bom
    {
        return Bom::query()
            ->with(['items.material', 'items.materialType', 'items.laborType'])
            ->where('model_id', $variant->model_id)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('size_id')->orWhere('size_id', $variant->size_id))
            ->orderByRaw('size_id IS NULL')
            ->orderByDesc('effective_from')
            ->first();
    }

    public function resolveMaterial(BomItem $item, ProductVariant $variant): ?Material
    {
        if ($item->color_mode === BomColorMode::Fixed) {
            return $item->material;
        }

        return Material::query()
            ->where('material_type_id', $item->material_type_id)
            ->where('color_id', $variant->color_id)
            ->first();
    }

    /**
     * Peringatan resep model PRODUKSI tanpa ongkos yang dibayar sebagai upah jahit / upah potong
     * (upah SPK model itu akan Rp0). Kosong = resep lengkap.
     *
     * @return list<string>
     */
    public function wageWarnings(Bom $bom): array
    {
        $bom->loadMissing(['model', 'items.laborType']);

        if ($bom->model?->kind !== ProductKind::Produksi) {
            return [];
        }

        $paid = $bom->items->map(fn (BomItem $item): ?WageType => $item->line_type === BomLineType::Labor ? $item->laborType?->wage_type : null)->filter();
        $warnings = [];

        foreach (WageType::cases() as $type) {
            if (! $paid->contains($type)) {
                $warnings[] = "Resep {$bom->model->code} belum punya ongkos yang dibayar sebagai {$type->getLabel()}: {$type->getLabel()} SPK model ini akan Rp0.";
            }
        }

        return $warnings;
    }

    /**
     * Harga per satuan dasar dari pembelian atau stok awal yang sudah berlaku pada tanggal tersebut.
     */
    public function latestUnitCost(Material $material, CarbonInterface|string $date): string
    {
        $purchases = fn () => MaterialPurchaseItem::query()
            ->join('material_purchases', 'material_purchases.id', '=', 'material_purchase_items.purchase_id')
            ->where('material_purchase_items.material_id', $material->id);

        $openingCost = fn () => MaterialMovement::query()
            ->where('material_id', $material->id)
            ->where('movement_type', MaterialMovementType::Opening)
            ->whereNotNull('unit_cost');

        $cost = $purchases()
            ->whereDate('material_purchases.purchase_date', '<=', $date)
            ->orderByDesc('material_purchases.purchase_date')
            ->orderByDesc('material_purchase_items.id')
            ->value('material_purchase_items.unit_cost')
            ?? $openingCost()->where('moved_at', '<', Carbon::parse($date)->addDay()->startOfDay())->orderByDesc('moved_at')->value('unit_cost');

        if ($cost === null) {
            throw new BusinessRuleException("Harga bahan {$material->code} belum tersedia pada tanggal tersebut.");
        }

        return (string) $cost;
    }

    /**
     * @return array{material_cost: float, labor_cost: float, sewing_cost: float, cutting_cost: float, materials: list<array{material: Material, qty_per_unit: float, unit_cost: float}>}
     */
    public function calculate(ProductVariant $variant, CarbonInterface|string $date): array
    {
        $bom = $this->bomFor($variant, $date);

        if (! $bom) {
            throw new BusinessRuleException("Resep (BOM) untuk {$variant->sku} belum ada pada tanggal tersebut.");
        }

        $materialCost = 0.0;
        $sewingCost = 0.0;
        $cuttingCost = 0.0;
        $laborCost = 0.0;
        $materials = [];

        foreach ($bom->items as $item) {
            if ($item->line_type === BomLineType::Labor) {
                $laborCost += (float) $item->labor_rate * (float) $item->qty_per_unit;

                // Upah SPK menurut "Dibayar sebagai" di Jenis Ongkos Kerja (bukan kode).
                if ($item->laborType?->wage_type === WageType::Jahit) {
                    $sewingCost += (float) $item->labor_rate * (float) $item->qty_per_unit;
                }

                if ($item->laborType?->wage_type === WageType::Potong) {
                    $cuttingCost += (float) $item->labor_rate * (float) $item->qty_per_unit;
                }

                continue;
            }

            $material = $this->resolveMaterial($item, $variant);

            if (! $material) {
                throw new BusinessRuleException("Bahan {$item->materialType?->name} untuk warna varian {$variant->sku} belum terdaftar di Stok Bahan.");
            }

            $unitCost = (float) $this->latestUnitCost($material, $date);
            $materialCost += $unitCost * (float) $item->qty_per_unit;
            $materials[] = ['material' => $material, 'qty_per_unit' => (float) $item->qty_per_unit, 'unit_cost' => $unitCost];
        }

        return [
            'material_cost' => round($materialCost, 2),
            'labor_cost' => round($laborCost, 2),
            'sewing_cost' => round($sewingCost, 2),
            'cutting_cost' => round($cuttingCost, 2),
            'materials' => $materials,
        ];
    }

    /**
     * Pembelian beli jadi terakhir (harga per pcs terisi) yang sudah diterima pada tanggal tersebut.
     *
     * @return array{unit_cost: float, date: string}|null
     */
    public function latestPurchase(ProductVariant $variant, CarbonInterface|string $date): ?array
    {
        $item = GoodsReceiptItem::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.receipt_id')
            ->where('goods_receipt_items.variant_id', $variant->id)
            ->where('goods_receipts.source', GoodsReceiptSource::BeliJadi)
            ->whereNotNull('goods_receipt_items.unit_cost')
            ->where('goods_receipts.received_at', '<', Carbon::parse($date)->addDay()->startOfDay())
            ->orderByDesc('goods_receipts.received_at')
            ->orderByDesc('goods_receipt_items.id')
            ->first(['goods_receipt_items.unit_cost', 'goods_receipts.received_at']);

        return $item === null ? null : [
            'unit_cost' => (float) $item->unit_cost,
            'date' => Carbon::parse($item->received_at)->toDateString(),
        ];
    }

    /**
     * Biaya packing per pcs pada tanggal: riwayat packing varian (berlaku mulai) > isian varian > pengaturan default
     * (packing_cost_default Rp3.000 untuk beli jadi, packing_cost_default_production untuk produksi, default 0).
     */
    public function packingCostFor(ProductVariant $variant, CarbonInterface|string|null $date = null): float
    {
        $history = $variant->packingCosts()
            ->whereDate('effective_from', '<=', Carbon::parse($date ?? today())->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if ($history !== null && $history->amount !== null) {
            return (float) $history->amount;
        }

        if ($history === null && $variant->packing_cost !== null) {
            return (float) $variant->packing_cost;
        }

        return $this->defaultPackingFor($variant);
    }

    public function defaultPackingFor(ProductVariant $variant): float
    {
        return (float) ($variant->isReadyMade()
            ? Setting::valueOf('packing_cost_default', 3000)
            : Setting::valueOf('packing_cost_default_production', 0));
    }

    /**
     * Tanggal berlaku HPP terbaru yang bisa dihitung hari ini: tanggal resep (produksi) atau tanggal pembelian (beli jadi).
     */
    public function costDateFor(ProductVariant $variant, CarbonInterface|string|null $date = null): ?string
    {
        $date ??= today();

        return $variant->isReadyMade()
            ? $this->latestPurchase($variant, $date)['date'] ?? null
            : $this->bomFor($variant, $date)?->effective_from?->toDateString();
    }

    /**
     * Simpan HPP sebagai riwayat (effective_from = tanggal hitung): dari resep (produksi) atau harga beli + packing (beli jadi).
     */
    public function refreshVariantCost(ProductVariant $variant, CarbonInterface|string|null $date = null): VariantCost
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        if ($variant->isReadyMade()) {
            $purchase = $this->latestPurchase($variant, $date)
                ?? throw new BusinessRuleException("Harga beli {$variant->sku} belum ada pada tanggal {$date}. Input Barang Masuk sumber Beli Jadi dengan harga per pcs.");

            return VariantCost::updateOrCreate(
                ['variant_id' => $variant->id, 'effective_from' => $date],
                ['material_cost' => round($purchase['unit_cost'], 2), 'labor_cost' => $packing = round($this->packingCostFor($variant, $date), 2), 'packing_cost' => $packing, 'source' => VariantCostSource::Purchase],
            );
        }

        $cost = $this->calculate($variant, $date);
        $packing = round($this->packingCostFor($variant, $date), 2);

        // Produksi: HPP = bahan + ongkos resep + packing (packing ikut labor_cost, dicatat terpisah di packing_cost).
        return VariantCost::updateOrCreate(
            ['variant_id' => $variant->id, 'effective_from' => $date],
            ['material_cost' => $cost['material_cost'], 'labor_cost' => round($cost['labor_cost'] + $packing, 2), 'packing_cost' => $packing, 'source' => VariantCostSource::Bom],
        );
    }
}
