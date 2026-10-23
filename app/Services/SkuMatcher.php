<?php

namespace App\Services;

use App\Models\Color;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\SkuAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Saran pemetaan SKU marketplace/BigSeller -> varian sistem.
 *
 * Penalaran dari teks SKU mentah (mis. "L006-Navy-M" atau "BC22-Hitam-2XL (LD 118 cm)"), nama produk
 * (mis. "CHICLO Kemeja Kimono ... - L006") dan nama variasi marketplace (mis. "Hitam,2XL (LD 118 cm)"):
 *  - keterangan dalam kurung di SKU (mis. lingkar dada) diabaikan;
 *  - kode MODEL disebut di SKU atau nama produk (bobot terbesar);
 *  - WARNA (kode/nama, termasuk nama 2 kata "Biru Navy") dan UKURAN (S, M, XL, ...) harus cocok dengan varian:
 *    dicari di SKU dulu, lalu nama variasi, lalu nama produk; yang terdeteksi tapi berbeda mengurangi skor;
 *  - kemiripan kata nama produk dengan nama model/varian, dan kemiripan teks SKU dengan SKU / alias yang sudah ada.
 * Skor 0-100; >= HIGH_CONFIDENCE dianggap cukup yakin untuk diterapkan massal, >= AUTO_MAP untuk dipetakan
 * otomatis saat impor (hanya bila nama produk / variasi ikut diisi).
 */
class SkuMatcher
{
    public const HIGH_CONFIDENCE = 80;

    public const AUTO_MAP = 90;

    /** @var Collection<int, array{variant: ProductVariant, model: string, color: ?string, color_name: ?string, size: ?string, words: list<string>, keys: list<string>}>|null */
    private ?Collection $index = null;

    /** @var array<string, string>|null teks warna (kode & nama, huruf kecil) => kode warna */
    private ?array $colors = null;

    /** @var array<string, true>|null kode ukuran (huruf kecil) */
    private ?array $sizes = null;

    /** @var array<string, list<array{variant: ProductVariant, score: int, reasons: list<string>}>> */
    private array $cache = [];

    /**
     * @return list<array{variant: ProductVariant, score: int, reasons: list<string>}> urut skor tertinggi
     */
    public function suggest(?string $sku, ?string $productName = null, int $limit = 5, ?string $variation = null): array
    {
        $sku = self::cleanSku($sku);
        $productName = trim((string) $productName);
        $variation = trim((string) $variation);

        if ($sku === '' && $productName === '' && $variation === '') {
            return [];
        }

        return $this->cache[Str::lower($sku.'|'.$productName.'|'.$variation.'|'.$limit)] ??= $this->rank($sku, $productName, $variation, $limit);
    }

    /**
     * Saran terbaik bila skornya >= $minScore dan jelas lebih unggul dari saran kedua.
     *
     * @return array{variant: ProductVariant, score: int, reasons: list<string>}|null
     */
    public function best(?string $sku, ?string $productName = null, int $minScore = self::HIGH_CONFIDENCE, ?string $variation = null): ?array
    {
        $suggestions = $this->suggest($sku, $productName, 2, $variation);
        $first = $suggestions[0] ?? null;

        if ($first === null || $first['score'] < $minScore) {
            return null;
        }

        return isset($suggestions[1]) && $suggestions[1]['score'] >= $first['score'] ? null : $first;
    }

    public function label(array $suggestion): string
    {
        return "{$suggestion['variant']->sku} — {$suggestion['variant']->name} ({$suggestion['score']}%".($suggestion['reasons'] === [] ? '' : ': '.implode(', ', $suggestion['reasons'])).')';
    }

    /**
     * @return list<array{variant: ProductVariant, score: int, reasons: list<string>}>
     */
    /**
     * SKU tanpa keterangan dalam kurung: "BC22-Hitam-2XL (LD 118 cm)" => "BC22-Hitam-2XL".
     */
    public static function cleanSku(?string $sku): string
    {
        return Str::squish((string) preg_replace('/\([^)]*\)/', ' ', (string) $sku));
    }

    private function rank(string $sku, string $productName, string $variation, int $limit): array
    {
        $skuTokens = $this->tokens($sku);
        $nameTokens = $this->tokens($productName);
        $variationTokens = $this->tokens((string) preg_replace('/\([^)]*\)/', ' ', $variation));
        $allTokens = array_values(array_unique([...$skuTokens, ...$variationTokens, ...$nameTokens]));
        $text = ' '.implode(' ', $allTokens).' ';
        $skuText = ' '.implode(' ', $skuTokens).' ';
        $isSize = fn (string $token): bool => isset($this->sizes()[$token]);

        // Warna: dari SKU dulu (lebih spesifik), lalu variasi, lalu nama produk; nama warna terpanjang didahulukan ("biru navy" > "navy").
        $color = $this->detectColor($skuText) ?? $this->detectColor(' '.implode(' ', $variationTokens).' ') ?? $this->detectColor($text);
        $size = collect($skuTokens)->first($isSize)
            ?? collect($variationTokens)->first($isSize)
            ?? collect($nameTokens)->last($isSize);

        return $this->index()
            ->map(function (array $entry) use ($skuTokens, $nameTokens, $color, $size, $sku): array {
                $score = 0;
                $reasons = [];

                if (in_array($entry['model'], $skuTokens, true)) {
                    $score += 45;
                    $reasons[] = 'model '.Str::upper($entry['model']);
                } elseif (in_array($entry['model'], $nameTokens, true)) {
                    $score += 35;
                    $reasons[] = 'model '.Str::upper($entry['model']).' di nama produk';
                } elseif (collect($skuTokens)->contains(fn (string $token): bool => strlen($token) >= 2 && str_starts_with($entry['model'], $token))) {
                    $score += 15;
                }

                if ($color !== null) {
                    if ($entry['color'] === $color) {
                        $score += 20;
                        $reasons[] = 'warna '.$entry['color_name'];
                    } else {
                        $score -= 15;
                    }
                }

                if ($size !== null) {
                    if ($entry['size'] === $size) {
                        $score += 20;
                        $reasons[] = 'ukuran '.Str::upper($size);
                    } else {
                        $score -= 15;
                    }
                } elseif ($entry['size'] === null) {
                    $score += 5; // varian tanpa ukuran untuk SKU tanpa ukuran
                }

                if ($nameTokens !== [] && $entry['words'] !== []) {
                    $common = count(array_intersect($entry['words'], $nameTokens));
                    $similarity = $common / max(1, min(count($entry['words']), count($nameTokens)));
                    $score += (int) round(15 * $similarity);

                    if ($similarity >= 0.5) {
                        $reasons[] = 'nama mirip';
                    }
                }

                if ($sku !== '') {
                    $best = collect($entry['keys'])->max(fn (string $key): float => $this->textSimilarity(Str::lower($sku), $key));
                    $score += (int) round(10 * $best);
                    if ($best === 1.0) {
                        $score = 100;
                        $reasons = ['SKU sama'];
                    }
                }

                // Tanpa bukti model, kecocokan warna/ukuran saja tidak cukup.
                if (! collect($reasons)->contains(fn (string $reason): bool => str_starts_with($reason, 'model') || $reason === 'nama mirip' || $reason === 'SKU sama')) {
                    $score = min($score, 40);
                }

                return ['variant' => $entry['variant'], 'score' => max(0, min(100, $score)), 'reasons' => $reasons];
            })
            ->filter(fn (array $suggestion): bool => $suggestion['score'] >= 25)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) ?: [], fn (string $token): bool => $token !== ''));
    }

    private function detectColor(string $text): ?string
    {
        foreach ($this->colors() as $needle => $code) {
            if (str_contains($text, ' '.$needle.' ')) {
                return $code;
            }
        }

        return null;
    }

    private function textSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        similar_text(preg_replace('/[^a-z0-9]/', '', $a), preg_replace('/[^a-z0-9]/', '', $b), $percent);

        return min(0.99, $percent / 100);
    }

    /**
     * @return Collection<int, array{variant: ProductVariant, model: string, color: ?string, color_name: ?string, size: ?string, words: list<string>, keys: list<string>}>
     */
    private function index(): Collection
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $aliases = SkuAlias::query()->get(['variant_id', 'alias'])->groupBy('variant_id');

        return $this->index = ProductVariant::query()
            ->where('is_active', true)
            ->with(['model', 'color', 'size'])
            ->get()
            ->map(fn (ProductVariant $variant): array => [
                'variant' => $variant,
                'model' => Str::lower((string) $variant->model?->code),
                'color' => $variant->color?->code,
                'color_name' => $variant->color?->name,
                'size' => $variant->size ? Str::lower($variant->size->code) : null,
                'words' => array_values(array_unique(array_filter(
                    $this->tokens(($variant->model?->name ?? '').' '.$variant->name),
                    fn (string $word): bool => strlen($word) >= 3,
                ))),
                'keys' => [Str::lower($variant->sku), ...($aliases->get($variant->id)?->map(fn (SkuAlias $alias): string => Str::lower($alias->alias))->all() ?? [])],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function colors(): array
    {
        if ($this->colors !== null) {
            return $this->colors;
        }

        $colors = [];

        foreach (Color::query()->get(['code', 'name']) as $color) {
            foreach ([$color->name, $color->code] as $text) {
                $needle = implode(' ', $this->tokens((string) $text));

                if ($needle !== '' && strlen($needle) >= 2) {
                    $colors[$needle] ??= $color->code;
                }
            }
        }

        uksort($colors, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->colors = $colors;
    }

    /**
     * @return array<string, true>
     */
    private function sizes(): array
    {
        return $this->sizes ??= Size::query()->pluck('code')->mapWithKeys(fn (string $code): array => [Str::lower($code) => true])->all();
    }
}
