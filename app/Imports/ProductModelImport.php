<?php

namespace App\Imports;

use App\Enums\ProductKind;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Services\PricingService;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Model produk: dicocokkan lewat KODE MODEL; yang sudah ada diperbarui dari sel yang terisi.
 * Margin / jenis berubah = harga reseller semua varian model dihitung ulang mulai hari ini.
 */
class ProductModelImport extends RowImporter
{
    public function __construct(private PricingService $pricing) {}

    public function title(): string
    {
        return 'Model Produk';
    }

    public function permission(): string
    {
        return 'kelola katalog';
    }

    public function columns(): array
    {
        return [
            'KODE MODEL' => ['code', 'Ya', 'Kode unik, mis. BC1. Model yang sudah ada diperbarui.'],
            'NAMA MODEL' => ['name', 'Model baru', 'Default = kode.'],
            'KATEGORI' => ['category', '-', 'Nama kategori; belum ada = dibuat otomatis. Model baru tanpa kategori = Umum.'],
            'JENIS PRODUK' => ['kind', '-', 'Produksi / Beli Jadi / Jasa. Default Produksi. Beli Jadi: HPP dari harga beli + packing.'],
            'MARGIN MODEL %' => ['margin', '-', 'Mis. 30 atau 30%. Kosong = ikut pengaturan (produksi: margin_default; beli jadi: margin_ready_made_pct + nominal).'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
            'JUMLAH VARIAN' => ['variant_count', '-', 'Info dari sistem.'],
        ];
    }

    protected function systemColumns(): array
    {
        return ['JUMLAH VARIAN'];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function notes(): array
    {
        return [
            'Varian (SKU) diimpor di menu Varian / SKU; workbook lengkap (bahan, produk, resep, pembelian) ada di Pusat Import / Export.',
            'Mengosongkan sel tidak menghapus nilai. Menghapus baris di Excel tidak menghapus model; gunakan AKTIF = Tidak.',
        ];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Kategori' => [[30], [['KATEGORI'], ...ProductCategory::query()->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE MODEL']);
        $row->maxLength(['code' => ['KODE MODEL', 30], 'name' => ['NAMA MODEL', 150], 'category' => ['KATEGORI', 60]]);
        $kind = $row->enum('kind', ProductKind::class, 'JENIS PRODUK');
        $margin = $row->percent('margin', 'MARGIN MODEL %');
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $category = $row->filled('category') ? $this->category($row->text('category')) : null;
        $attributes = array_filter([
            'name' => $row->text('name') ?: null,
            'category_id' => $category?->id,
            'kind' => $kind?->value,
            'default_margin_pct' => $margin,
            'is_active' => $active,
        ], fn ($value): bool => $value !== null);

        $model = $this->findCi(ProductModel::withTrashed(), 'code', $code);

        if ($model?->trashed()) {
            throw new BusinessRuleException("model {$code} sudah dihapus");
        }

        if ($model === null) {
            ProductModel::create([
                'code' => $code,
                'name' => $code,
                'category_id' => $category?->id ?? $this->category('Umum')->id,
                ...$attributes,
            ]);
            $this->bump('created');

            return;
        }

        $model->fill($attributes);
        $priceChanged = $model->isDirty(['default_margin_pct', 'kind']);
        $this->saveIfDirty($model);

        if ($priceChanged) {
            foreach ($this->pricing->syncVariants($model->variants()->get(), today())['skipped'] as $skipped) {
                $this->warn("harga {$skipped}");
            }
        }
    }

    protected function exportRows(): iterable
    {
        foreach (ProductModel::query()->with('category')->withCount('variants')->orderBy('code')->lazy() as $model) {
            yield [
                $model->code, $model->name, $model->category?->name, $model->kind->getLabel(),
                $this->percentText($model->default_margin_pct),
                $model->is_active ? 'Ya' : 'Tidak', $model->variants_count,
            ];
        }
    }

    private function category(string $name): ProductCategory
    {
        $category = $this->findCi(ProductCategory::query(), 'name', $name);

        if ($category === null) {
            $category = ProductCategory::create(['name' => Str::limit($name, 60, '')]);
            $this->bump('created', 'Kategori');
        }

        return $category;
    }

    private function percentText(mixed $value): ?string
    {
        return $value === null ? null : rtrim(rtrim(number_format((float) $value * 100, 2, '.', ''), '0'), '.').'%';
    }
}
