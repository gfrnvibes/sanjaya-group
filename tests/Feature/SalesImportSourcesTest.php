<?php

use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Models\Color;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Size;
use App\Models\SkuAlias;
use App\Models\VariantCost;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderSpreadsheet;
use App\Support\Spreadsheet;
use OpenSpout\Reader\XLSX\Reader;

/** @param array<string, mixed> $options */
function importOrders(array $f, string $csv, array $options = [], string $fileName = 'orders.csv'): array
{
    $path = tempnam(sys_get_temp_dir(), 'orders-');
    file_put_contents($path, $csv);
    try {
        return app(SalesOrderImporter::class)->import($path, $fileName, [
            'warehouse_id' => $f['warehouse']->id, 'status' => null, 'source' => 'manual', ...$options,
        ]);
    } finally {
        unlink($path);
    }
}

/** Model BC22 (Hitam / Putih, M / 2XL) dengan SKU sistem bergaya "BC22HM". @return array<string, ProductVariant> */
function chefJacketVariants(array $f): array
{
    $model = ProductModel::create(['code' => 'BC22', 'name' => 'Chef Jacket Seragam', 'category_id' => $f['category']->id, 'kind' => 'produksi']);
    $colors = ['H' => Color::create(['code' => 'H', 'name' => 'Hitam']), 'P' => Color::create(['code' => 'P', 'name' => 'Putih'])];
    $sizes = ['M' => Size::create(['code' => 'M', 'sort_order' => 2]), '2XL' => Size::create(['code' => '2XL', 'sort_order' => 5])];
    $variants = [];

    foreach ($colors as $colorCode => $color) {
        foreach ($sizes as $sizeCode => $size) {
            $variant = ProductVariant::create(['model_id' => $model->id, 'color_id' => $color->id, 'size_id' => $size->id, 'sku' => "BC22{$colorCode}{$sizeCode}", 'name' => "Chef Jacket {$color->name} {$sizeCode}", 'is_active' => true]);
            $cost = VariantCost::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'material_cost' => 60, 'labor_cost' => 0, 'source' => 'manual']);
            ResellerPriceHistory::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'base_price' => 100, 'variant_cost_id' => $cost->id, 'source' => 'manual']);
            $variants[$variant->sku] = $variant;
        }
    }

    return $variants;
}

const BIGSELLER_HEADER = "Nomor Pesanan,Nama Panggilan Toko BigSeller,Waktu Pesanan Dibuat,Nama Produk,SKU,Nama Variasi,Jumlah,Harga Satuan,Subtotal Produk\n";

it('parses BigSeller month-name dates', function () {
    expect(Spreadsheet::parseDate('16 Okt 2026 19:54'))->toBe('2026-10-16')
        ->and(Spreadsheet::parseDate('1 Agustus 2026'))->toBe('2026-08-01')
        ->and(Spreadsheet::parseDate('05 Dec 2026'))->toBe('2026-12-05')
        ->and(Spreadsheet::parseDate('31 Feb 2026'))->toBeNull();
});

it('imports a BigSeller order export: several product rows per order number, repeated SKUs, SKUs with notes in brackets', function () {
    $f = businessFixture(0);
    $variants = chefJacketVariants($f);
    $csv = BIGSELLER_HEADER
        ."261016RUSMKERN,Shop,16 Okt 2026 19:54,ARR SANJAYA Chef Jacket - 022,BC22HM (LD 100 cm),\"Hitam,M (LD 100 cm)\",2,252700,505400\n"
        ."261016RUSMKERN,Shop,16 Okt 2026 19:54,ARR SANJAYA Chef Jacket - 022,BC22P2XL (LD 118 cm),\"Putih,2XL (LD 118 cm)\",3,259350,778050\n"
        ."2610204YTQP9JE,Shop,20 Okt 2026 12:00,ARR SANJAYA Chef Jacket - 022,BC22PM (LD 100 cm),\"Putih,M (LD 100 cm)\",2,246382.5,492765\n"
        ."2610204YTQP9JE,Shop,20 Okt 2026 12:00,ARR SANJAYA Chef Jacket - 022,BC22PM (LD 100 cm),\"Putih,M (LD 100 cm)\",4,259350,1037400\n";

    $result = importOrders($f, $csv, ['source' => 'bigseller']);
    $again = importOrders($f, $csv, ['source' => 'bigseller'], 'renamed.csv');

    expect($result['errors'])->toBe([])
        ->and($result['created'])->toBe(4)
        ->and($again['created'])->toBe(0)
        ->and($again['skipped'])->toBe(4);

    $orders = SalesOrder::query()->with('items')->orderBy('id')->get();
    expect($orders->pluck('order_no')->all())->toBe(['261016RUSMKERN', '261016RUSMKERN', '2610204YTQP9JE', '2610204YTQP9JE'])
        ->and($orders->pluck('order_date')->map->toDateString()->unique()->values()->all())->toBe(['2026-10-16', '2026-10-20'])
        ->and($orders->map(fn (SalesOrder $order): array => [$order->items->sole()->variant_id, $order->items->sole()->qty, (float) $order->items->sole()->revenue])->all())
        ->toBe([
            [$variants['BC22HM']->id, 2, 505400.0],
            [$variants['BC22P2XL']->id, 3, 778050.0],
            [$variants['BC22PM']->id, 2, 492765.0],
            [$variants['BC22PM']->id, 4, 1037400.0],
        ])
        // Status tidak dipilih: barang baru dikirim => Diproses.
        ->and($orders->pluck('status')->unique()->all())->toBe([SalesOrderStatus::Pending]);
});

it('completes shipped (Diproses) orders when the same file is imported again with status Selesai', function () {
    $f = businessFixture(0);
    chefJacketVariants($f);
    $csv = BIGSELLER_HEADER."A1,Shop,16 Okt 2026 19:54,Chef Jacket,BC22HM,\"Hitam,M\",2,100,200\n";

    importOrders($f, $csv, ['source' => 'bigseller']);
    expect(SalesOrder::query()->sole()->status)->toBe(SalesOrderStatus::Pending);

    $completed = importOrders($f, $csv, ['source' => 'bigseller', 'status' => 'completed']);
    expect($completed['created'])->toBe(1)
        ->and(SalesOrder::query()->sole()->status)->toBe(SalesOrderStatus::Completed);
    $this->assertDatabaseCount('sales_order_items', 1);
});

it('numbers manual, Shopee, TikTok and Lazada imports automatically even if a NO PESANAN column is present', function (string $source) {
    $f = businessFixture();
    $result = importOrders($f, "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN\n2026-10-01,Shop,001234,1,150,X1\n2026-10-01,Shop,001234,2,300,X1\n", ['source' => $source, 'status' => 'completed']);

    expect($result['errors'])->toBe([])
        ->and($result['created'])->toBe(2)
        ->and($result['batch']->source->value)->toBe($source)
        ->and(SalesOrder::query()->pluck('order_no')->every(fn (string $no): bool => str_starts_with($no, 'IMP')))->toBeTrue();
})->with(['manual', 'shopee', 'tiktok', 'lazada']);

it('requires an order number in the Website format and rejects a file in the wrong format for the chosen source', function () {
    $f = businessFixture();

    $website = importOrders($f, "TANGGAL,NAMA TOKO,NO PESANAN,NAMA PRODUK,SKU,NAMA VARIASI,QTY,PENDAPATAN TOKO\n2026-10-01,Shop,WEB-1,Variant,001234,,1,150\n", ['source' => 'website']);
    $wrong = importOrders($f, "TANGGAL,NAMA TOKO,SKU VARIAN,QTY\n2026-10-01,Shop,001234,1\n", ['source' => 'website']);

    expect($website['errors'])->toBe([])
        ->and(SalesOrder::query()->sole()->order_no)->toBe('WEB-1')
        ->and($wrong['created'])->toBe(0)
        ->and($wrong['errors'][0])->toContain('Header tidak sesuai format Website');
});

it('maps unknown website SKUs automatically from SKU, product name and variation name', function () {
    $f = businessFixture(0);
    $variants = chefJacketVariants($f);
    $csv = "TANGGAL,NAMA TOKO,NO PESANAN,NAMA PRODUK,SKU,NAMA VARIASI,QTY,PENDAPATAN TOKO\n"
        ."2026-10-01,Shop,W1,Chef Jacket Seragam BC22,BC22-Putih-2XL (LD 118 cm),\"Putih,2XL (LD 118 cm)\",1,150\n"
        ."2026-10-01,Shop,W2,Topi Koki,TOPI-01,Hitam,1,50\n";

    $result = importOrders($f, $csv, ['source' => 'website', 'auto_map' => true]);

    expect($result['created'])->toBe(1)
        ->and($result['mapped'])->toBe(['BC22-Putih-2XL (LD 118 cm)' => 'BC22P2XL'])
        ->and(SkuAlias::query()->where('alias', 'BC22-Putih-2XL (LD 118 cm)')->value('variant_id'))->toBe($variants['BC22P2XL']->id)
        ->and(SkuAlias::query()->value('source'))->toBe('website')
        ->and(SalesOrderItem::query()->sole()->variant_id)->toBe($variants['BC22P2XL']->id);

    // SKU yang tidak yakin tetap masuk Import Perlu Dicek, lengkap dengan nama variasi untuk saran manual.
    $failure = SalesImportFailure::query()->sole();
    expect($failure->value('NAMA VARIASI'))->toBe('Hitam')
        ->and($failure->rawSku())->toBe('TOPI-01');
});

it('leaves unknown SKUs for review when automatic mapping is off', function () {
    $f = businessFixture(0);
    chefJacketVariants($f);

    $result = importOrders($f, "TANGGAL,NAMA TOKO,NO PESANAN,NAMA PRODUK,SKU,NAMA VARIASI,QTY\n2026-10-01,Shop,W1,Chef Jacket BC22,BC22-Putih-2XL,\"Putih,2XL\",1\n", ['source' => 'website']);

    expect($result['created'])->toBe(0)
        ->and(SkuAlias::query()->count())->toBe(0)
        ->and(SalesImportFailure::query()->sole()->skuSuggestions(1)[0]['variant']->sku)->toBe('BC22P2XL');
});

it('builds a template with the columns of each source', function (string $source, array $expected) {
    $path = app(SalesOrderSpreadsheet::class)->template(SalesImportSource::from($source));
    $reader = new Reader;
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $header = $row->toArray();
            break 2;
        }
    }
    $reader->close();
    @unlink($path);

    expect($header)->toBe($expected);
})->with([
    'bigseller' => ['bigseller', ['Nomor Pesanan', 'Nama Panggilan Toko BigSeller', 'Waktu Pesanan Dibuat', 'Nama Produk', 'SKU', 'Nama Variasi', 'Jumlah', 'Harga Satuan', 'Subtotal Produk']],
    'website' => ['website', ['TANGGAL', 'NAMA TOKO', 'NO PESANAN', 'NAMA PRODUK', 'SKU', 'NAMA VARIASI', 'QTY', 'PENDAPATAN TOKO']],
    'lazada' => ['lazada', ['TANGGAL', 'NAMA TOKO', 'SKU VARIAN', 'SKU TOKO', 'NAMA PRODUK', 'NAMA VARIASI', 'QTY', 'PENDAPATAN TOKO']],
]);
