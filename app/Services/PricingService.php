<?php

namespace App\Services;

use App\Enums\BomColorMode;
use App\Enums\PriceSource;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\MissingPriceException;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\Setting;
use App\Models\VariantCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Harga reseller: harga dasar berlaku x (1 + % tier pemilik), plus HPP berlaku.
 * Harga dasar dibuat OTOMATIS, tidak diisi manual:
 *  - produksi : HPP resep x (1 + margin);
 *  - beli jadi: (harga beli + packing) x (1 + margin beli jadi %) + margin beli jadi nominal.
 */
class PricingService
{
    public function __construct(private CostingService $costing) {}

    /**
     * Nilai snapshot untuk baris penjualan.
     *
     * @return array{reseller_price_id: int, tier_adjustment_pct: float, reseller_unit_price: float, hpp_unit: float}
     */
    public function snapshot(int $variantId, int $ownerId, CarbonInterface|string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $price = DB::selectOne('select price_id, base_price from fn_base_price(?, ?)', [$variantId, $date]);

        if (! $price) {
            $sku = ProductVariant::query()->whereKey($variantId)->value('sku');

            throw new MissingPriceException("Harga reseller untuk {$sku} belum ada pada tanggal {$date}.");
        }

        $tierPct = (float) DB::scalar('select fn_owner_tier_pct(?, ?)', [$ownerId, $date]);
        $rounding = (float) Setting::valueOf('tier_price_rounding', 100) ?: 1;

        $hpp = VariantCost::query()
            ->where('variant_id', $variantId)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->value('total_cost');

        if ($hpp === null) {
            throw new MissingPriceException('HPP varian belum tersedia pada tanggal '.$date.'. Lengkapi harga bahan/resep terlebih dahulu.');
        }

        return [
            'reseller_price_id' => (int) $price->price_id,
            'tier_adjustment_pct' => $tierPct,
            'reseller_unit_price' => round((float) $price->base_price * (1 + $tierPct) / $rounding) * $rounding,
            'hpp_unit' => (float) ($hpp ?? 0),
        ];
    }

    /**
     * Margin % berlaku varian: margin varian > margin model > pengaturan
     * (margin_default 30% untuk produksi, margin_ready_made_pct 5% untuk beli jadi).
     */
    public function marginFor(ProductVariant $variant): float
    {
        $variant->loadMissing('model');

        return (float) ($variant->margin_pct
            ?? $variant->model?->default_margin_pct
            ?? ($variant->isReadyMade() ? Setting::valueOf('margin_ready_made_pct', 0.05) : Setting::valueOf('margin_default', 0.30)));
    }

    /**
     * Margin nominal (Rp per pcs) di atas margin %: hanya beli jadi (pengaturan margin_ready_made_amount, default 2000).
     */
    public function marginAmountFor(ProductVariant $variant): float
    {
        return $variant->isReadyMade() ? (float) Setting::valueOf('margin_ready_made_amount', 2000) : 0.0;
    }

    /**
     * Harga dasar = HPP x (1 + margin) + margin nominal, dibulatkan ke atas sesuai pengaturan price_rounding
     * (sama dengan v_variant_pricing).
     */
    public function basePriceFor(float $totalCost, float $margin, float $marginAmount = 0.0): float
    {
        $rounding = (float) Setting::valueOf('price_rounding', 100) ?: 100;

        return ceil(round(($totalCost * (1 + $margin) + $marginAmount) / $rounding, 6)) * $rounding;
    }

    /**
     * HARGA OTOMATIS: hitung ulang HPP pada tanggal $date (resep, atau harga beli + packing untuk beli jadi),
     * lalu simpan harga dasar yang berlaku mulai tanggal itu.
     *
     * @throws BusinessRuleException resep/bahan/harga beli belum lengkap, atau harga tanggal itu sudah dipakai pesanan
     */
    public function syncPrice(ProductVariant $variant, CarbonInterface|string $date): ResellerPriceHistory
    {
        $date = Carbon::parse($date)->toDateString();
        $cost = $this->costing->refreshVariantCost($variant, $date)->refresh();
        $margin = $this->marginFor($variant);
        $marginAmount = $this->marginAmountFor($variant);
        $basePrice = $this->basePriceFor((float) $cost->total_cost, $margin, $marginAmount);

        $price = $variant->priceHistories()->whereDate('effective_from', $date)->first();

        if ($price !== null && (float) $price->base_price !== $basePrice && $price->salesOrderItems()->exists()) {
            throw new BusinessRuleException("harga tanggal {$date} sudah dipakai pesanan, tidak diubah");
        }

        if ($price === null) {
            $previous = $variant->priceHistories()->whereDate('effective_from', '<', $date)->orderByDesc('effective_from')->first();

            // Harga sebelumnya sudah sama: tidak perlu baris riwayat baru.
            if ($previous !== null && (float) $previous->base_price === $basePrice) {
                return $previous;
            }
        }

        return ResellerPriceHistory::updateOrCreate(
            ['variant_id' => $variant->id, 'effective_from' => $date],
            [
                'base_price' => $basePrice,
                'variant_cost_id' => $cost->id,
                'margin_pct' => $margin,
                'source' => PriceSource::Auto,
                'approved_by' => auth()->id(),
                'note' => $variant->isReadyMade()
                    ? 'Otomatis dari harga beli + packing + margin '.round($margin * 100, 2).'% + Rp'.number_format($marginAmount, 0, ',', '.')
                    : 'Otomatis dari resep + margin',
            ],
        );
    }

    /**
     * Sinkronkan HPP & harga beberapa varian. Tanggal kosong = tanggal resep yang dipakai hari ini
     * (beli jadi: tanggal pembelian terakhir).
     *
     * @param  iterable<ProductVariant>  $variants
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncVariants(iterable $variants, CarbonInterface|string|null $date = null): array
    {
        $synced = 0;
        $skipped = [];

        foreach ($variants as $variant) {
            $effectiveFrom = $date ?? $this->costing->costDateFor($variant);

            if ($effectiveFrom === null) {
                $skipped[] = $variant->isReadyMade()
                    ? "{$variant->sku}: produk beli jadi belum punya harga beli (Barang Masuk sumber Beli Jadi), harga belum bisa dihitung."
                    : "{$variant->sku}: belum punya resep (BOM), harga belum bisa dihitung.";

                continue;
            }

            try {
                DB::transaction(fn () => $this->syncPrice($variant, $effectiveFrom));
                $synced++;
            } catch (BusinessRuleException $exception) {
                $skipped[] = "{$variant->sku}: ".rtrim($exception->getMessage(), '.').'.';
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    /**
     * Resep disimpan: harga semua varian model (dan ukuran resep) berlaku mulai tanggal resep.
     *
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncForBom(Bom $bom): array
    {
        $variants = ProductVariant::query()
            ->where('model_id', $bom->model_id)
            ->when($bom->size_id, fn ($query, $sizeId) => $query->where('size_id', $sizeId))
            ->get();

        return $this->syncVariants($variants, $bom->effective_from);
    }

    /**
     * Barang masuk beli jadi: harga varian beli jadi berlaku mulai tanggal terima.
     *
     * @param  iterable<int>  $variantIds
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncForReadyMade(iterable $variantIds, CarbonInterface|string $date): array
    {
        $variants = ProductVariant::query()
            ->with('model')
            ->whereIn('id', collect($variantIds)->unique()->all())
            ->get()
            ->filter(fn (ProductVariant $variant): bool => $variant->isReadyMade());

        return $this->syncVariants($variants, Carbon::parse($date)->toDateString());
    }

    /**
     * Pembelian bahan: harga varian yang resepnya memakai bahan tersebut, berlaku mulai tanggal pembelian.
     *
     * @param  iterable<int>  $materialIds
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncForMaterials(iterable $materialIds, CarbonInterface|string $date): array
    {
        return $this->syncVariants($this->variantsUsingMaterials($materialIds), $date);
    }

    /**
     * Varian yang resepnya memakai bahan: bahan tetap, atau jenis bahan "ikut warna produk" dengan warna sama.
     *
     * @param  iterable<int>  $materialIds
     * @return list<ProductVariant>
     */
    public function variantsUsingMaterials(iterable $materialIds): array
    {
        $variants = collect();

        foreach (Material::query()->whereIn('id', collect($materialIds)->unique()->all())->get() as $material) {
            $fixedModels = BomItem::query()
                ->join('boms', 'boms.id', '=', 'bom_items.bom_id')
                ->where('bom_items.material_id', $material->id)
                ->pluck('boms.model_id');
            $followModels = BomItem::query()
                ->join('boms', 'boms.id', '=', 'bom_items.bom_id')
                ->where('bom_items.material_type_id', $material->material_type_id)
                ->where('bom_items.color_mode', BomColorMode::FollowVariant)
                ->pluck('boms.model_id');

            $variants = $variants
                ->merge(ProductVariant::query()->whereIn('model_id', $fixedModels)->get())
                ->merge(ProductVariant::query()->whereIn('model_id', $followModels)->where('color_id', $material->color_id)->get());
        }

        return $variants->unique('id')->values()->all();
    }
}
