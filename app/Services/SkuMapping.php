<?php

namespace App\Services;

use App\Enums\ImportFailureReason;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Imports\ImportRegistry;
use App\Models\ProductVariant;
use App\Models\SalesImportFailure;
use App\Models\SkuAlias;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Baris "SKU tidak ditemukan" yang SKU-nya SEKARANG sudah dikenali sistem (dipetakan lewat menu Pemetaan SKU,
     * import, katalog, atau varian baru) — cukup dicoba ulang, tidak perlu dipetakan lagi.
     * Aturan pengenalan sama dengan SalesOrderImporter::resolveVariant (SKU VARIAN dulu; lalu alias / SKU varian).
     *
     * @return Collection<int, SalesImportFailure>
     */
    public function resolvableFailures(): Collection
    {
        $failures = $this->openSkuFailures()->orderBy('id')->get();
        $candidates = $failures->flatMap(fn (SalesImportFailure $failure): array => $this->candidates($failure))->unique()->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $known = $candidates->chunk(1000)->flatMap(fn (Collection $chunk): array => [
            ...SkuAlias::query()->whereIn(DB::raw('lower(alias)'), $chunk->all())->pluck('alias')->all(),
            ...ProductVariant::query()->whereIn(DB::raw('lower(sku)'), $chunk->all())->pluck('sku')->all(),
        ])->map(fn (string $sku): string => Str::lower(trim($sku)))->flip();

        return $failures
            ->filter(fn (SalesImportFailure $failure): bool => collect($this->candidates($failure))->contains(fn (string $sku): bool => isset($known[$sku])))
            ->values();
    }

    /**
     * Coba ulang baris yang SKU-nya sudah dikenali (lihat resolvableFailures).
     *
     * @param  array<string, mixed>  $options  gudang/status pesanan (default: gudang barang jadi default, selesai)
     * @return array{fixed: int, failed: int, errors: list<string>, remaining: int}
     */
    public function retryResolvable(array $options, int $limit = 500): array
    {
        $failures = $this->resolvableFailures();

        if ($failures->isEmpty()) {
            return ['fixed' => 0, 'failed' => 0, 'errors' => [], 'remaining' => 0];
        }

        $retry = ImportRegistry::retry(ImportRegistry::SALES_ORDERS, $failures->take($limit), [], $options);

        return [...$retry, 'remaining' => max(0, $failures->count() - $limit)];
    }

    /**
     * Setelah pemetaan SKU disimpan dari mana pun, coba ulang baris gagal yang kini dikenali — sekali per request,
     * setelah respons terkirim (pengguna tidak menunggu).
     */
    public static function retryAfterResponse(): void
    {
        // Penanda per instance aplikasi (per request / per test), bukan static.
        if (app()->bound(self::RETRY_SCHEDULED)) {
            return;
        }

        app()->instance(self::RETRY_SCHEDULED, true);

        app()->terminating(function (): void {
            app()->forgetInstance(self::RETRY_SCHEDULED);

            try {
                app(self::class)->retryResolvable(self::defaultOptions());
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @return array{warehouse_id: ?int, status: string, source: string, order_date: null}
     */
    public static function defaultOptions(): array
    {
        return [
            'warehouse_id' => Warehouse::defaultForGoods()?->id,
            'status' => SalesOrderStatus::Completed->value,
            'source' => 'manual',
            'order_date' => null,
        ];
    }

    private const RETRY_SCHEDULED = 'sku-mapping.retry-scheduled';

    /**
     * SKU yang dicoba importer untuk baris ini (huruf kecil).
     *
     * @return list<string>
     */
    private function candidates(SalesImportFailure $failure): array
    {
        if (($sku = $failure->value('SKU VARIAN')) !== null) {
            return [Str::lower(trim($sku))];
        }

        $raw = $failure->value('SKU BIG SELLER');

        return $raw === null ? [] : array_values(array_unique(array_map(
            fn (string $sku): string => Str::lower(trim($sku)),
            array_filter([$raw, SkuMatcher::cleanSku($raw)]),
        )));
    }

    /**
     * Rencana pemetaan otomatis: per SKU BIG SELLER, saran terbaik yang cukup yakin (>= SkuMatcher::HIGH_CONFIDENCE).
     *
     * @return list<array{alias: string, variant: ProductVariant, score: int, count: int}>
     */
    public function plan(): array
    {
        $known = $this->resolvableFailures()->pluck('id')->all();

        return $this->openSkuFailures()
            ->when($known !== [], fn (Builder $query) => $query->whereKeyNot($known))
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
     * Ada SKU marketplace yang masih perlu dipetakan (di baris tertentu, atau di semua baris)? Versi ringan unmappedSkus().
     *
     * @param  list<int>|null  $failureIds
     */
    public function hasUnmapped(?array $failureIds = null): bool
    {
        $known = $this->resolvableFailures()->pluck('id')->all();

        return $this->openSkuFailures()
            ->when($failureIds !== null, fn (Builder $query) => $query->whereKey($failureIds))
            ->when($known !== [], fn (Builder $query) => $query->whereKeyNot($known))
            ->whereRaw("coalesce(\"values\"->>'SKU BIG SELLER', '') <> ''")
            ->exists();
    }

    /**
     * Daftar SKU marketplace yang gagal (1 baris per SKU, terbanyak dulu) untuk pemetaan massal,
     * lengkap dengan contoh nama produk / variasi dan saran varian terbaik.
     *
     * @param  list<int>|null  $failureIds  batasi ke baris gagal tertentu (bulk action: baris yang dicentang); null = semua
     * @return list<array{alias: string, product: ?string, variation: ?string, count: int, suggestion: ?array{variant: ProductVariant, score: int, reasons: list<string>}}>
     */
    public function unmappedSkus(?array $failureIds = null): array
    {
        // SKU yang sudah dipetakan di tempat lain tidak perlu dipetakan lagi (cukup dicoba ulang).
        $known = $this->resolvableFailures()->pluck('id')->all();

        return $this->openSkuFailures()
            ->when($failureIds !== null, fn (Builder $query) => $query->whereKey($failureIds))
            ->when($known !== [], fn (Builder $query) => $query->whereKeyNot($known))
            ->get()
            ->filter(fn (SalesImportFailure $failure): bool => $failure->value('SKU BIG SELLER') !== null)
            ->groupBy(fn (SalesImportFailure $failure): string => Str::lower($failure->value('SKU BIG SELLER')))
            ->map(function ($failures): array {
                /** @var SalesImportFailure $first */
                $first = $failures->first();

                return [
                    'alias' => $first->value('SKU BIG SELLER'),
                    'product' => $first->itemName(),
                    'variation' => $first->value('NAMA VARIASI'),
                    'count' => $failures->count(),
                    'suggestion' => $first->skuSuggestions(1)[0] ?? null,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Simpan banyak pemetaan sekaligus lalu coba ulang semua baris gagal dengan SKU tersebut.
     *
     * @param  array<string, int>  $mappings  SKU marketplace => ID varian
     * @param  array{warehouse_id: int, status: ?string}  $options
     * @return array{mapped: int, fixed: int, failed: int, errors: list<string>}
     */
    public function mapMany(array $mappings, array $options): array
    {
        $variants = ProductVariant::query()->whereIn('id', array_values($mappings))->get()->keyBy('id');
        $mapped = [];
        $errors = [];

        foreach ($mappings as $alias => $variantId) {
            $variant = $variants->get($variantId);

            try {
                $variant ?? throw new BusinessRuleException("Varian untuk SKU \"{$alias}\" tidak ditemukan.");
                $this->map((string) $alias, $variant);
                $mapped[] = Str::lower(trim((string) $alias));
            } catch (BusinessRuleException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($mapped === []) {
            return ['mapped' => 0, 'fixed' => 0, 'failed' => 0, 'errors' => $errors];
        }

        $failures = $this->openSkuFailures()
            ->whereRaw("lower(\"values\"->>'SKU BIG SELLER') in (".implode(',', array_fill(0, count($mapped), '?')).')', $mapped)
            ->orderBy('id')
            ->get();
        $retry = ImportRegistry::retry(ImportRegistry::SALES_ORDERS, $failures, [], $options);

        return ['mapped' => count($mapped), 'fixed' => $retry['fixed'], 'failed' => $retry['failed'], 'errors' => [...$errors, ...$retry['errors']]];
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
