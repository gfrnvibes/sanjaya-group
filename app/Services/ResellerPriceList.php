<?php

namespace App\Services;

use App\Models\PriceTier;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DAFTAR HARGA RESELLER per tier pada tanggal tertentu, untuk dicetak / disimpan PDF dan dibagikan ke reseller.
 * Harga = harga dasar berlaku x (1 + % tier), dibulatkan sesuai pengaturan tier_price_rounding (sama dengan harga pesanan).
 * Tidak memuat HPP / margin.
 */
class ResellerPriceList
{
    /**
     * @param  list<int>  $tierIds  kosong = semua tier
     * @param  array{category_ids?: list<int>, kind?: ?string, model_ids?: list<int>, search?: ?string}  $scope
     * @return array{tiers: Collection<int, PriceTier>, groups: Collection<string, Collection<string, Collection<int, object>>>, count: int, date: Carbon}
     */
    public function build(array $tierIds, Carbon $date, array $scope = []): array
    {
        $tiers = PriceTier::query()
            ->when($tierIds !== [], fn ($query) => $query->whereIn('id', $tierIds))
            ->orderBy('adjustment_pct')
            ->orderBy('code')
            ->get();
        $rounding = (float) Setting::valueOf('tier_price_rounding', 100) ?: 1;

        $rows = DB::table('product_variants as v')
            ->join('product_models as m', 'm.id', '=', 'v.model_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'm.category_id')
            ->leftJoin('colors as co', 'co.id', '=', 'v.color_id')
            ->leftJoin('sizes as s', 's.id', '=', 'v.size_id')
            ->joinLateral(
                DB::table('reseller_price_histories as p')
                    ->select('p.base_price')
                    ->whereColumn('p.variant_id', 'v.id')
                    ->whereDate('p.effective_from', '<=', $date->toDateString())
                    ->orderByDesc('p.effective_from')
                    ->limit(1),
                'price',
            )
            ->whereNull('v.deleted_at')
            ->whereNull('m.deleted_at')
            ->where('v.is_active', true)
            ->where('m.is_active', true)
            ->when($scope['category_ids'] ?? [], fn ($query, array $ids) => $query->whereIn('m.category_id', $ids))
            ->when($scope['model_ids'] ?? [], fn ($query, array $ids) => $query->whereIn('m.id', $ids))
            ->when($scope['kind'] ?? null, fn ($query, string $kind) => $query->where('m.kind', $kind))
            ->when($scope['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->whereLike('v.sku', "%{$search}%")
                ->orWhereLike('v.name', "%{$search}%")
                ->orWhereLike('m.name', "%{$search}%")))
            ->orderByRaw('c.name NULLS LAST, m.name, co.name NULLS FIRST, s.sort_order NULLS FIRST, s.code NULLS FIRST, v.sku')
            ->get(['v.sku', 'v.name', 'm.name as model', 'c.name as category', 'co.name as color', 's.code as size', 'price.base_price']);

        foreach ($rows as $row) {
            $row->prices = $tiers->mapWithKeys(fn (PriceTier $tier): array => [
                $tier->id => round((float) $row->base_price * (1 + (float) $tier->adjustment_pct) / $rounding) * $rounding,
            ])->all();
        }

        return [
            'tiers' => $tiers,
            'groups' => $rows->groupBy(fn ($row): string => $row->category ?? 'Tanpa kategori')
                ->map(fn (Collection $rows): Collection => $rows->groupBy('model')),
            'count' => $rows->count(),
            'date' => $date,
        ];
    }

    /**
     * @param  list<int>  $tierIds
     * @param  array{category_ids?: list<int>, kind?: ?string, model_ids?: list<int>, search?: ?string}  $scope
     */
    public function html(array $tierIds, Carbon $date, array $scope = [], ?string $note = null): string
    {
        return view('print.price-list', [
            ...$this->build($tierIds, $date, $scope),
            'note' => $note,
            'printedBy' => auth()->user()?->name,
        ])->render();
    }

    /**
     * Label di hasil cetak (dibaca reseller): kode tier saja, mis. "Tier 6", tanpa nama & persen kenaikan.
     */
    public static function printLabel(PriceTier $tier): string
    {
        return Str::title(Str::lower($tier->code));
    }

    public static function tierLabel(PriceTier $tier): string
    {
        $pct = (float) $tier->adjustment_pct * 100;

        return $tier->name.($pct != 0 ? ' ('.($pct > 0 ? '+' : '').rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',').'%)' : '');
    }
}
