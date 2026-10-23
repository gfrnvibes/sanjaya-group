<?php

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Imports\GoodsReceiptImport;
use App\Imports\ImportRegistry;
use App\Imports\WarehouseImport;
use App\Models\Color;
use App\Models\GoodsReceipt;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\SalesImportFailure;
use App\Models\Size;
use App\Models\SkuAlias;
use App\Models\User;
use App\Models\VariantCost;
use App\Models\Warehouse;
use App\Services\CatalogImporter;
use App\Services\SalesOrderImporter;
use App\Services\SkuMapping;
use App\Services\SkuMatcher;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Role;

function reviewAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

/** @param list<list<mixed>> $rows */
function reviewCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'review-').'.csv';
    $handle = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

/** @return array{0: ProductVariant, 1: ProductVariant} */
function linenVariants(array $f): array
{
    $model = ProductModel::create(['code' => 'L006', 'name' => 'Kemeja Kimono Linen', 'category_id' => $f['category']->id, 'kind' => 'produksi']);
    $navy = Color::create(['code' => 'NV', 'name' => 'Navy']);
    $black = Color::create(['code' => 'HT', 'name' => 'Hitam']);
    $m = Size::create(['code' => 'M', 'sort_order' => 2]);
    $l = Size::create(['code' => 'L', 'sort_order' => 3]);

    $variants = [
        ProductVariant::create(['model_id' => $model->id, 'color_id' => $navy->id, 'size_id' => $m->id, 'sku' => 'L006NVM', 'name' => 'Kemeja Kimono Linen Navy M', 'is_active' => true]),
        ProductVariant::create(['model_id' => $model->id, 'color_id' => $black->id, 'size_id' => $l->id, 'sku' => 'L006HTL', 'name' => 'Kemeja Kimono Linen Hitam L', 'is_active' => true]),
    ];

    foreach ($variants as $variant) {
        $cost = VariantCost::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'material_cost' => 60000, 'labor_cost' => 0, 'source' => 'manual']);
        ResellerPriceHistory::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'base_price' => 100000, 'variant_cost_id' => $cost->id, 'source' => 'manual']);
    }

    return $variants;
}

it('records failed rows of any resource import under its category and fixes them via direct edit', function () {
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([['KODE GUDANG', 'NAMA GUDANG', 'JENIS'], ['TOKO2', 'Gudang Toko 2', 'Lemari']]);

    $result = app(WarehouseImport::class)->import($path, 'gudang.csv');
    unlink($path);

    $failure = SalesImportFailure::query()->sole();
    expect($result['errors'])->toHaveCount(1)
        ->and($failure->importer)->toBe('warehouse')
        ->and($failure->reason)->toBe(ImportFailureReason::InvalidValue)
        ->and($failure->value('JENIS'))->toBe('Lemari')
        ->and($failure->file_name)->toBe('gudang.csv');

    // Notifikasi lonceng ke pengimpor.
    expect(auth()->user()->notifications()->count())->toBe(1);

    Livewire::test(ListSalesImportFailures::class, ['activeTab' => ImportRegistry::tab('warehouse')])
        ->assertCanSeeTableRecords([$failure])
        ->callAction(TestAction::make('edit')->table($failure), data: ['field_0' => 'TOKO2', 'field_1' => 'Gudang Toko 2', 'field_2' => 'Barang Jadi', 'field_3' => ''])
        ->assertHasNoFormErrors();

    expect($failure->fresh()->status)->toBe(ImportFailureStatus::Fixed)
        ->and(Warehouse::where('code', 'TOKO2')->value('kind')->value)->toBe('barang_jadi');
});

it('retries a multi-row document together and creates it only once', function () {
    businessFixture();
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([
        ['NO DOKUMEN', 'TANGGAL', 'GUDANG', 'SKU VARIAN', 'QTY'],
        ['BM-T1', '2026-10-01', 'GOODS', '001234', 5],
        ['BM-T1', '2026-10-01', 'GOODS', 'SALAH', 3],
    ]);
    app(GoodsReceiptImport::class)->import($path, 'masuk.csv');
    unlink($path);

    $failures = SalesImportFailure::query()->orderBy('line')->get();
    expect($failures)->toHaveCount(2)
        ->and($failures->pluck('group_key')->unique())->toHaveCount(1);

    $result = ImportRegistry::retry('goods_receipt', $failures, [$failures[1]->id => ['SKU VARIAN' => '001234']]);

    expect($result['fixed'])->toBe(2)
        ->and(GoodsReceipt::where('receipt_no', 'BM-T1')->count())->toBe(1)
        ->and((int) GoodsReceipt::where('receipt_no', 'BM-T1')->first()->items()->sum('qty'))->toBe(8);
});

it('writes a per-category fix template that re-imports and resolves the failure', function () {
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([['KODE GUDANG', 'JENIS'], ['TOKO3', 'Lemari']]);
    app(WarehouseImport::class)->import($path, 'gudang.csv');
    unlink($path);
    $failure = SalesImportFailure::query()->sole();

    $template = ImportRegistry::fixTemplate('warehouse', SalesImportFailure::query());
    $reader = new Reader;
    $reader->open($template);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    expect($rows[0])->toContain('ID GAGAL', 'ALASAN GAGAL')
        ->and($rows[1][0])->toBe('TOKO3');

    // Perbaiki kolom JENIS lalu import file perbaikan.
    $fixed = reviewCsv([$rows[0], array_replace($rows[1], [array_search('JENIS', $rows[0], true) => 'Bahan Baku'])]);
    $result = ImportRegistry::import('warehouse', $fixed, 'perbaikan.csv');
    unlink($fixed);
    unlink($template);

    expect($result['errors'])->toBe([])
        ->and($failure->fresh()->status)->toBe(ImportFailureStatus::Fixed);
});

it('records catalog sheet failures per sheet category and retries them from edited values', function () {
    businessFixture();
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([['KODE MODEL', 'UKURAN', 'SKU VARIAN', 'MIN STOK'], ['MODEL', 'XL', 'NEW-1', 'banyak']]);
    app(CatalogImporter::class)->import($path, 'produk.csv', ['products']);
    unlink($path);

    $failure = SalesImportFailure::query()->sole();
    expect($failure->importer)->toBe('catalog.products');

    $result = ImportRegistry::retry('catalog.products', collect([$failure]), [$failure->id => ['MIN STOK' => '5']]);

    expect($result['fixed'])->toBe(1)
        ->and(ProductVariant::where('sku', 'NEW-1')->value('min_stock'))->toBe(5);
});

it('suggests the variant from model code, colour, size and product name', function () {
    $f = businessFixture();
    [$navyM, $blackL] = linenVariants($f);

    $suggestions = app(SkuMatcher::class)->suggest('L006-Navy-M', 'CHICLO Kemeja Kimono Linen Cotton Unisex Premium - L006');

    expect($suggestions[0]['variant']->id)->toBe($navyM->id)
        ->and($suggestions[0]['score'])->toBeGreaterThanOrEqual(SkuMatcher::HIGH_CONFIDENCE)
        ->and(app(SkuMatcher::class)->best('L006-Hitam-L', 'Kemeja Kimono Linen')['variant']->id)->toBe($blackL->id)
        ->and(app(SkuMatcher::class)->best('TOTALLY-OTHER', 'Topi'))->toBeNull();
});

it('maps a marketplace SKU from the review page and fixes every row with that SKU, keeping the product name', function () {
    $f = businessFixture();
    [$navyM] = linenVariants($f);
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([
        ['Nomor Pesanan', 'Nama Panggilan Toko BigSeller', 'Waktu Pesanan Dibuat', 'Nama Produk', 'SKU', 'Nama Variasi', 'Jumlah', 'Harga Satuan', 'Subtotal Produk'],
        ['P1', 'Shop', '1 Okt 2026 09:00', 'CHICLO Kemeja Kimono Linen - L006', 'L006-Navy-M', 'Navy,M', 1, 137984, 137984],
        ['P2', 'Shop', '2 Okt 2026 09:00', 'CHICLO Kemeja Kimono Linen - L006', 'L006-Navy-M', 'Navy,M', 2, 135000, 270000],
    ]);
    app(SalesOrderImporter::class)->import($path, 'bigseller.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'bigseller']);
    unlink($path);

    $failures = SalesImportFailure::query()->orderBy('id')->get();
    expect($failures)->toHaveCount(2)
        ->and($failures[0]->itemName())->toBe('CHICLO Kemeja Kimono Linen - L006')
        ->and($failures[0]->reason)->toBe(ImportFailureReason::SkuNotFound);

    Livewire::test(ListSalesImportFailures::class)
        ->callAction(TestAction::make('mapSku')->table($failures[0]), data: [
            'suggestion' => $navyM->id, 'save_alias' => true, 'warehouse_id' => $f['warehouse']->id, 'status' => 'completed',
        ])
        ->assertHasNoFormErrors();

    expect(SkuAlias::where('alias', 'L006-Navy-M')->value('variant_id'))->toBe($navyM->id)
        ->and($failures->map->fresh()->pluck('status')->unique()->all())->toBe([ImportFailureStatus::Fixed]);
    $this->assertDatabaseCount('sales_orders', 2);
});

it('applies confident SKU suggestions in bulk', function () {
    $f = businessFixture();
    [$navyM] = linenVariants($f);
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([
        ['TANGGAL', 'NAMA TOKO', 'SKU BIG SELLER', 'QTY', 'NAMA PRODUK'],
        ['2026-10-01', 'Shop', 'L006-Navy-M', 1, 'Kemeja Kimono Linen L006'],
        ['2026-10-01', 'Shop', 'ZZZ-UNKNOWN', 1, 'Barang lain'],
    ]);
    app(SalesOrderImporter::class)->import($path, 'manual.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);
    unlink($path);

    $result = app(SkuMapping::class)->applyPlan(['warehouse_id' => $f['warehouse']->id, 'status' => 'completed']);

    expect($result['mapped'])->toBe(1)
        ->and($result['fixed'])->toBe(1)
        ->and(SalesImportFailure::query()->open()->count())->toBe(1)
        ->and(SkuAlias::where('alias', 'L006-Navy-M')->value('variant_id'))->toBe($navyM->id);
});

it('renders the review page with category tabs and the overview widget', function () {
    businessFixture();
    $this->actingAs(reviewAdmin());
    $path = reviewCsv([['KODE GUDANG', 'JENIS'], ['X1', 'Lemari']]);
    app(WarehouseImport::class)->import($path, 'gudang.csv');
    unlink($path);

    $this->get('/administrator/import-perlu-dicek')->assertOk()->assertSee('Import Perlu Dicek')->assertSee('Gudang');
    $this->get('/administrator/import-perlu-dicek?tab=warehouse')->assertOk()->assertSee('Unduh template Gudang');
});
