<?php

namespace App\Services;

use App\Enums\PriceSource;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantCost;
use App\Models\VariantPricing;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Harga reseller: harga dasar berlaku x (1 + % tier pemilik), plus HPP berlaku.
 */
class PricingService
{
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

            throw new BusinessRuleException("Harga reseller untuk {$sku} belum ada pada tanggal {$date}.");
        }

        $tierPct = (float) DB::scalar('select fn_owner_tier_pct(?, ?)', [$ownerId, $date]);
        $rounding = (float) Setting::valueOf('tier_price_rounding', 100) ?: 1;

        $hpp = VariantCost::query()
            ->where('variant_id', $variantId)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->value('total_cost');

        return [
            'reseller_price_id' => (int) $price->price_id,
            'tier_adjustment_pct' => $tierPct,
            'reseller_unit_price' => round((float) $price->base_price * (1 + $tierPct) / $rounding) * $rounding,
            'hpp_unit' => (float) ($hpp ?? 0),
        ];
    }

    /**
     * Setujui usulan harga (v_variant_pricing) menjadi harga dasar baru.
     */
    public function approveProposal(VariantPricing $pricing, CarbonInterface|string $effectiveFrom, ?User $approver = null): ResellerPriceHistory
    {
        if ($pricing->proposed_price === null) {
            throw new BusinessRuleException("{$pricing->sku} belum punya HPP, usulan harga tidak bisa disetujui.");
        }

        $date = Carbon::parse($effectiveFrom)->toDateString();
        $costId = VariantCost::query()
            ->where('variant_id', $pricing->variant_id)
            ->whereDate('effective_from', '<=', today())
            ->orderByDesc('effective_from')
            ->value('id');

        return ResellerPriceHistory::updateOrCreate(
            ['variant_id' => $pricing->variant_id, 'effective_from' => $date],
            [
                'base_price' => $pricing->proposed_price,
                'variant_cost_id' => $costId,
                'margin_pct' => $pricing->margin_pct,
                'source' => PriceSource::Auto,
                'approved_by' => $approver?->id ?? auth()->id(),
            ],
        );
    }
}
