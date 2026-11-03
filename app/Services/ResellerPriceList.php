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
    public const BY_MODEL = 'model';

    public const BY_SKU = 'sku';

    public const BY_SIZE = 'size';

    public const LAYOUTS = [self::BY_SIZE => 'Tabel ukuran (harga per ukuran)', self::BY_MODEL => 'Per model (ukuran diringkas)', self::BY_SKU => 'Per SKU (rinci, dengan warna)'];

    /**
     * Tampilan MODEL: satu entri per model + baris harga; varian berharga sama digabung,
     * jadi baris harga dipecah hanya bila harganya beda per ukuran / warna (label mis. "S–XL" atau "2XL · Hitam").
     * Tampilan UKURAN (bawaan): satu baris per model, kolom harga per ukuran (kolom = semua ukuran di kategori itu).
     * Warna hanya tampil di tampilan SKU (warna tidak membedakan harga).
     * Tampilan SKU: rincian per varian (SKU, warna, ukuran), dikelompokkan per model.
     *
     * @param  list<int>  $tierIds  kosong = semua tier
     * @param  array{category_ids?: list<int>, kind?: ?string, model_ids?: list<int>, search?: ?string}  $scope
     * @param  'model'|'size'|'sku'  $layout
     * @return array{tiers: Collection<int, PriceTier>, groups: Collection<string, Collection<array-key, mixed>>, count: int, date: Carbon, layout: string}
     */
    public function build(array $tierIds, Carbon $date, array $scope = [], string $layout = self::BY_SIZE): array
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
            ->orderByRaw('c.name NULLS LAST, m.name, s.sort_order NULLS FIRST, s.code NULLS FIRST, co.name NULLS FIRST')
            ->get(['m.id as model_id', 'm.code', 'm.name as model', 'v.sku', 'v.name', 'c.name as category', 'co.name as color', 's.code as size', 's.sort_order as size_order', 'price.base_price']);

        foreach ($rows as $row) {
            $row->prices = $tiers->mapWithKeys(fn (PriceTier $tier): array => [
                $tier->id => round((float) $row->base_price * (1 + (float) $tier->adjustment_pct) / $rounding) * $rounding,
            ])->all();
        }

        $category = fn (object $item): string => $item->category ?? 'Tanpa kategori';

        if ($layout === self::BY_SKU) {
            return [
                'tiers' => $tiers,
                'groups' => $rows->groupBy($category)->map(fn (Collection $rows): Collection => $rows->groupBy('model')),
                'count' => $rows->count(),
                'date' => $date,
                'layout' => $layout,
            ];
        }

        $models = $rows->groupBy('model_id')->map(fn (Collection $variants): object => $this->model($variants));

        if ($layout === self::BY_SIZE) {
            return [
                'tiers' => $tiers,
                'groups' => $rows->groupBy($category)->map(fn (Collection $rows): array => [
                    // Kolom ukuran kategori, urut ukuran master; '' = produk tanpa ukuran.
                    'columns' => $rows->unique(fn (object $row): string => (string) $row->size)
                        ->sortBy([fn (object $a, object $b): int => [$a->size !== null, $a->size_order] <=> [$b->size !== null, $b->size_order]])
                        ->map(fn (object $row): string => (string) $row->size)->values()->all(),
                    'models' => $rows->pluck('model_id')->unique()->map(fn (int $id): object => $models[$id])->values(),
                ]),
                'count' => $models->count(),
                'date' => $date,
                'layout' => $layout,
            ];
        }

        return [
            'tiers' => $tiers,
            'groups' => $models->groupBy($category)->map(fn (Collection $models): Collection => $models->values()),
            'count' => $models->count(),
            'date' => $date,
            'layout' => self::BY_MODEL,
        ];
    }

    /**
     * @param  Collection<int, object>  $variants  varian satu model, urut ukuran
     */
    private function model(Collection $variants): object
    {
        $first = $variants->first();
        $sizes = $variants->pluck('size')->filter()->unique()->values()->all();
        $colors = $variants->pluck('color')->filter()->unique()->sort()->values()->all();

        $lines = $variants->groupBy(fn (object $variant): string => json_encode($variant->prices))
            ->map(function (Collection $same) use ($sizes, $colors): object {
                $lineSizes = $same->pluck('size')->filter()->unique()->values()->all();
                $lineColors = $same->pluck('color')->filter()->unique()->sort()->values()->all();
                $label = collect([
                    count($lineSizes) === count($sizes) ? null : self::ranges($sizes, $lineSizes),
                    count($lineColors) === count($colors) ? null : implode(', ', $lineColors),
                ])->filter()->implode(' · ');

                return (object) ['label' => $label === '' ? 'Semua ukuran' : $label, 'prices' => $same->first()->prices];
            })
            ->values();

        return (object) [
            'code' => $first->code,
            'name' => $first->model,
            'category' => $first->category,
            'colors' => $colors,
            'sizes' => $sizes === [] ? null : self::ranges($sizes, $sizes),
            'lines' => $lines,
            // Harga per ukuran per tier: [ukuran => [tier => [min, max]]] (min != max bila beda per warna).
            'bySize' => $variants->groupBy(fn (object $variant): string => (string) $variant->size)
                ->map(fn (Collection $same): array => collect($first->prices)->keys()->mapWithKeys(fn (int $tier): array => [
                    $tier => [$same->min(fn (object $variant): float => $variant->prices[$tier]), $same->max(fn (object $variant): float => $variant->prices[$tier])],
                ])->all())
                ->all(),
        ];
    }

    /**
     * Ukuran berurutan diringkas jadi rentang: [S, M, L, XL, 3XL] dari urutan model -> "S–XL, 3XL".
     *
     * @param  list<string>  $all  semua ukuran model (urut)
     * @param  list<string>  $picked
     */
    private static function ranges(array $all, array $picked): string
    {
        $index = array_flip($all);
        $positions = collect($picked)->map(fn (string $size): int => $index[$size])->sort()->values();
        $parts = [];
        $start = $previous = null;

        foreach ($positions->push(PHP_INT_MAX) as $position) {
            if ($start !== null && $position !== $previous + 1) {
                $parts[] = $start === $previous ? $all[$start] : ($previous === $start + 1 ? $all[$start].', '.$all[$previous] : $all[$start].'–'.$all[$previous]);
                $start = null;
            }

            $start ??= $position;
            $previous = $position;
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<int>  $tierIds
     * @param  array{category_ids?: list<int>, kind?: ?string, model_ids?: list<int>, search?: ?string}  $scope
     * @param  'model'|'size'|'sku'  $layout
     */
    public function html(array $tierIds, Carbon $date, array $scope = [], ?string $note = null, string $layout = self::BY_SIZE): string
    {
        return view('print.price-list', [
            ...$this->build($tierIds, $date, $scope, $layout),
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
