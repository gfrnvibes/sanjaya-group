<?php

namespace App\Services;

use App\Enums\BomLineType;
use App\Enums\GoodsReceiptSource;
use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceiptItem;
use App\Models\PriceTier;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\SupplierWholesalePrice;
use App\Models\VariantCost;
use Illuminate\Support\Collection;

/**
 * Komponen harga 1 varian (yang berlaku hari ini): HPP (bahan/ongkos atau harga beli/packing), margin,
 * harga dasar berlaku vs usulan, dan harga reseller per tier. Dipakai halaman Komponen Harga.
 * Varian perlu dimuat dengan relasi costs & priceHistories (urut terbaru dulu) dan model.
 */
class PriceBreakdown
{
    /** @var Collection<int, PriceTier>|null */
    private ?Collection $tiers = null;

    public function __construct(private PricingService $pricing, private CostingService $costing) {}

    public function cost(ProductVariant $variant): ?VariantCost
    {
        return $variant->costs->first(fn (VariantCost $cost): bool => $cost->effective_from->lte(today()));
    }

    public function price(ProductVariant $variant): ?ResellerPriceHistory
    {
        return $variant->priceHistories->first(fn (ResellerPriceHistory $price): bool => $price->effective_from->lte(today()));
    }

    /**
     * Margin berlaku, mis. "30% (default)" atau "5% (khusus)".
     */
    public function marginText(ProductVariant $variant): string
    {
        $percent = rtrim(rtrim(number_format($this->pricing->marginFor($variant) * 100, 2, ',', '.'), '0'), ',').'%';

        return $percent
            .($variant->margin_pct === null ? ($variant->model?->default_margin_pct === null ? ' (default)' : ' (model)') : ' (khusus)');
    }

    /**
     * Harga dasar usulan dari HPP berlaku + margin saat ini (sama dengan v_variant_pricing).
     */
    public function proposed(ProductVariant $variant): ?float
    {
        $cost = $this->cost($variant);

        return $cost === null ? null : $this->pricing->basePriceFor((float) $cost->total_cost, $this->pricing->marginFor($variant));
    }

    /**
     * Harga reseller untuk tier tertentu dari harga dasar berlaku.
     */
    public function tierPrice(ProductVariant $variant, PriceTier $tier): ?float
    {
        $price = $this->price($variant);

        if ($price === null) {
            return null;
        }

        $rounding = (float) Setting::valueOf('tier_price_rounding', 100) ?: 1;

        return round((float) $price->base_price * (1 + (float) $tier->adjustment_pct) / $rounding) * $rounding;
    }

    /**
     * Asal packing hari ini: "khusus mulai 01/09/2026", "khusus varian", atau "default".
     */
    public function packingNote(ProductVariant $variant): string
    {
        $history = $variant->packingCosts()->whereDate('effective_from', '<=', today())->orderByDesc('effective_from')->first();
        $next = $variant->packingCosts()->whereDate('effective_from', '>', today())->orderBy('effective_from')->first();

        $note = match (true) {
            $history !== null && $history->amount !== null => 'khusus mulai '.$history->effective_from->format('d/m/Y'),
            $history === null && $variant->packing_cost !== null => 'khusus varian',
            default => 'default',
        };

        return $note.($next ? ' · berubah '.$next->effective_from->format('d/m/Y') : '');
    }

    /**
     * Ringkasan resep (BOM) berlaku: "DRILL 120 cm · KANCING 5 pcs · Jahit Rp5.000".
     */
    public function bomSummary(ProductVariant $variant): ?string
    {
        $bom = $this->costing->bomFor($variant, today());

        if ($bom === null) {
            return null;
        }

        $number = fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');

        return $bom->loadMissing('items.laborType')->items
            ->map(fn ($item): string => $item->line_type === BomLineType::Labor
                ? ($item->laborType?->name ?? 'Ongkos').' Rp'.number_format((float) $item->labor_rate * (float) $item->qty_per_unit, 0, ',', '.')
                : ($this->costing->resolveMaterial($item, $variant)?->code ?? $item->materialType?->code ?? 'Bahan').' '.$number($item->qty_per_unit))
            ->implode(' · ');
    }

    /**
     * Riwayat harga dasar (terbaru dulu) untuk modal rincian.
     *
     * @return Collection<int, ResellerPriceHistory>
     */
    public function history(ProductVariant $variant, int $limit = 8): Collection
    {
        return $variant->priceHistories()->orderByDesc('effective_from')->limit($limit)->withCount('salesOrderItems')->get();
    }

    /**
     * Supplier beli jadi: lot FIFO berikutnya (masih ada sisa), selain itu lot terakhir.
     */
    public function supplierName(ProductVariant $variant): ?string
    {
        if (! $variant->isReadyMade()) {
            return null;
        }

        $lot = StockLot::query()->with('supplier')->where('variant_id', $variant->id)->whereNotNull('supplier_id')
            ->orderByRaw('qty_remaining > 0 DESC')
            ->orderByRaw('CASE WHEN qty_remaining > 0 THEN received_at END ASC')
            ->orderByDesc('received_at')
            ->first();

        return $lot?->supplier?->name;
    }

    /**
     * Harga grosir bordir yang berlaku hari ini, mis. "≥10 Rp13.000 · ≥20 Rp12.000 (Rendi)".
     */
    public function wholesaleText(ProductVariant $variant): ?string
    {
        $this->wholesale ??= SupplierWholesalePrice::query()->with('supplier')->whereDate('effective_from', '<=', today())
            ->orderByDesc('effective_from')->get()->groupBy('model_id');

        $prices = ($this->wholesale->get($variant->model_id) ?? collect())
            ->unique(fn (SupplierWholesalePrice $price): string => $price->supplier_id.'|'.$price->min_qty)
            ->sortBy('min_qty')
            ->groupBy('supplier_id');

        return $prices->isEmpty() ? null : $prices
            ->map(fn (Collection $tiers): string => $tiers->map(fn (SupplierWholesalePrice $price): string => '≥'.$price->min_qty.' Rp'.number_format((float) $price->unit_price, 0, ',', '.'))->implode(' · ')
                .' ('.$tiers->first()->supplier?->name.')')
            ->implode('; ');
    }

    /** @var Collection<int, Collection<int, SupplierWholesalePrice>>|null */
    private ?Collection $wholesale = null;

    /**
     * @return Collection<int, PriceTier>
     */
    public function tiers(): Collection
    {
        return $this->tiers ??= PriceTier::query()->orderBy('adjustment_pct')->orderBy('code')->get();
    }

    /**
     * Rincian HPP untuk modal: baris resep (bahan & ongkos) atau pembelian beli jadi terakhir.
     *
     * @return array{kind: string, lines: list<array{label: string, detail: string, qty: ?float, unit: ?float, subtotal: float}>, total: ?float, error: ?string, note: ?string}
     */
    public function detail(ProductVariant $variant): array
    {
        if ($variant->isReadyMade()) {
            $item = GoodsReceiptItem::query()
                ->with(['receipt.supplier'])
                ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.receipt_id')
                ->where('goods_receipt_items.variant_id', $variant->id)
                ->where('goods_receipts.source', GoodsReceiptSource::BeliJadi)
                ->whereNotNull('goods_receipt_items.unit_cost')
                ->where('goods_receipts.received_at', '<', today()->addDay())
                ->orderByDesc('goods_receipts.received_at')
                ->select('goods_receipt_items.*')
                ->first();
            $packing = $this->costing->packingCostFor($variant);
            $packingNote = $this->packingNote($variant);

            return [
                'kind' => 'beli_jadi',
                'lines' => array_values(array_filter([
                    $item ? [
                        'label' => 'Harga beli',
                        'detail' => 'Barang Masuk '.$item->receipt?->receipt_no.' · '.$item->receipt?->received_at?->format('d/m/Y').($item->receipt?->supplier ? ' · '.$item->receipt->supplier->name : ''),
                        'qty' => (float) $item->qty, 'unit' => (float) $item->unit_cost, 'subtotal' => (float) $item->unit_cost,
                    ] : null,
                    ['label' => 'Biaya packing', 'detail' => 'Packing '.$packingNote, 'qty' => null, 'unit' => null, 'subtotal' => $packing],
                ])),
                'total' => $item ? (float) $item->unit_cost + $packing : null,
                'error' => $item ? null : 'Belum ada Barang Masuk sumber Beli Jadi dengan harga per pcs.',
                'note' => 'Qty pada baris harga beli = jumlah pcs pada pembelian terakhir.',
            ];
        }

        $bom = $this->costing->bomFor($variant, today());

        if ($bom === null) {
            return ['kind' => 'produksi', 'lines' => [], 'total' => null, 'error' => 'Belum ada resep (BOM) yang berlaku.', 'note' => null];
        }

        $lines = [];
        $error = null;

        foreach ($bom->items as $item) {
            if ($item->line_type === BomLineType::Labor) {
                $item->loadMissing('laborType');
                $lines[] = [
                    'label' => 'Ongkos '.($item->laborType?->name ?? ''),
                    'detail' => 'Tarif per pcs',
                    'qty' => (float) $item->qty_per_unit, 'unit' => (float) $item->labor_rate,
                    'subtotal' => round((float) $item->labor_rate * (float) $item->qty_per_unit, 2),
                ];

                continue;
            }

            $material = $this->costing->resolveMaterial($item, $variant);

            try {
                $unit = $material ? (float) $this->costing->latestUnitCost($material, today()) : null;
            } catch (BusinessRuleException $exception) {
                $unit = null;
                $error = $exception->getMessage();
            }

            $lines[] = [
                'label' => $material?->code ?? ($item->materialType?->name ?? 'Bahan'),
                'detail' => ($material ? '' : 'Bahan warna varian belum terdaftar · ').'per '.($material?->base_uom?->value ?? 'satuan'),
                'qty' => (float) $item->qty_per_unit, 'unit' => $unit,
                'subtotal' => $unit === null ? 0.0 : round($unit * (float) $item->qty_per_unit, 2),
            ];
        }

        $packing = $this->costing->packingCostFor($variant);

        if ($packing > 0 || $variant->packingCosts()->exists()) {
            $lines[] = ['label' => 'Packing', 'detail' => 'Packing '.$this->packingNote($variant), 'qty' => null, 'unit' => null, 'subtotal' => $packing];
        }

        return [
            'kind' => 'produksi',
            'lines' => $lines,
            'total' => round(collect($lines)->sum('subtotal'), 2),
            'error' => $error,
            'note' => 'Resep berlaku mulai '.$bom->effective_from->format('d/m/Y').($bom->size_id ? ' (khusus ukuran)' : '').'. Harga bahan = pembelian terakhir.',
        ];
    }
}
