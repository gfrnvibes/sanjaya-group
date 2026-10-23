<?php

namespace App\Services;

use App\Enums\ImportFailureReason;
use App\Exceptions\BusinessRuleException;
use App\Imports\ImportRegistry;
use App\Models\ProductVariant;
use App\Models\SalesImportFailure;
use App\Models\SkuAlias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Pemetaan SKU dari "Import Perlu Dicek": simpan SKU marketplace sebagai Pemetaan SKU,
 * lalu coba ulang SEMUA baris pesanan yang gagal karena SKU tersebut sekaligus.
 */
class SkuMapping
{
    public function __construct(private SkuMatcher $matcher) {}

    /**
     * Baris pesanan terbuka yang gagal karena SKU tidak ditemukan.
     *
     * @return Builder<SalesImportFailure>
     */
    public function openSkuFailures(): Builder
    {
        return SalesImportFailure::query()
            ->open()
            ->where('importer', ImportRegistry::SALES_ORDERS)
            ->where('reason', ImportFailureReason::SkuNotFound)
            ->where('line', '>', 0);
    }

    /**
     * Rencana pemetaan otomatis: per SKU BIG SELLER, saran terbaik yang cukup yakin (>= SkuMatcher::HIGH_CONFIDENCE).
     *
     * @return list<array{alias: string, variant: ProductVariant, score: int, count: int}>
     */
    public function plan(): array
    {
        return $this->openSkuFailures()
            ->get()
            ->filter(fn (SalesImportFailure $failure): bool => $failure->value('SKU BIG SELLER') !== null)
            ->groupBy(fn (SalesImportFailure $failure): string => Str::lower($failure->value('SKU BIG SELLER')))
            ->map(function ($failures): ?array {
                /** @var SalesImportFailure $first */
                $first = $failures->first();
                $best = $this->matcher->best($first->value('SKU BIG SELLER'), $first->itemName(), variation: $first->value('NAMA VARIASI'));

                return $best === null ? null : [
                    'alias' => $first->value('SKU BIG SELLER'),
                    'variant' => $best['variant'],
                    'score' => $best['score'],
                    'count' => $failures->count(),
                ];
            })
            ->filter()
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Simpan pemetaan alias -> varian. Alias yang sudah dipetakan ke varian lain ditolak.
     */
    public function map(string $alias, ProductVariant $variant, string $source = 'bigseller'): void
    {
        $alias = trim($alias);
        $existing = SkuAlias::query()->whereRaw('lower(alias) = ?', [Str::lower($alias)])->first();

        if ($existing !== null && $existing->variant_id !== $variant->id) {
            throw new BusinessRuleException("SKU \"{$alias}\" sudah dipetakan ke varian lain; ubah di menu Pemetaan SKU.");
        }

        if (ProductVariant::query()->whereRaw('lower(sku) = ?', [Str::lower($alias)])->where('id', '!=', $variant->id)->exists()) {
            throw new BusinessRuleException("\"{$alias}\" adalah SKU varian lain; tidak bisa dijadikan pemetaan.");
        }

        $existing ?? SkuAlias::create(['alias' => Str::limit($alias, 120, ''), 'variant_id' => $variant->id, 'source' => $source]);
    }

    /**
     * Coba ulang semua baris terbuka dengan SKU BIG SELLER = $alias.
     *
     * @param  array{warehouse_id: int, status: string}  $options
     * @return array{fixed: int, failed: int, errors: list<string>}
     */
    public function retryAlias(string $alias, array $options): array
    {
        $failures = SalesImportFailure::query()
            ->open()
            ->where('importer', ImportRegistry::SALES_ORDERS)
            ->where('line', '>', 0)
            ->whereRaw("lower(\"values\"->>'SKU BIG SELLER') = ?", [Str::lower(trim($alias))])
            ->orderBy('id')
            ->get();

        return ImportRegistry::retry(ImportRegistry::SALES_ORDERS, $failures, [], $options);
    }

    /**
     * Petakan + coba ulang semua SKU dalam rencana otomatis.
     *
     * @param  array{warehouse_id: int, status: string}  $options
     * @return array{mapped: int, fixed: int, errors: list<string>}
     */
    public function applyPlan(array $options): array
    {
        $result = ['mapped' => 0, 'fixed' => 0, 'errors' => []];

        foreach ($this->plan() as $item) {
            try {
                $this->map($item['alias'], $item['variant']);
                $result['mapped']++;
                $retry = $this->retryAlias($item['alias'], $options);
                $result['fixed'] += $retry['fixed'];
                array_push($result['errors'], ...$retry['errors']);
            } catch (BusinessRuleException $exception) {
                $result['errors'][] = $exception->getMessage();
            }
        }

        return $result;
    }
}
