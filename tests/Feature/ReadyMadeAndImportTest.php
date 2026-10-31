<?php

use App\Enums\GoodsReceiptSource;
use App\Enums\VariantCostSource;
use App\Enums\WageType;
use App\Imports\ChannelImport;
use App\Imports\GoodsReceiptImport;
use App\Imports\OwnerImport;
use App\Imports\PriceTierImport;
use App\Imports\ProductModelImport;
use App\Models\Channel;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\LaborType;
use App\Models\Owner;
use App\Models\PriceTier;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Tailor;
use App\Models\TailorPayment;
use App\Models\VariantPricing;
use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Services\StockLedger;
use App\Services\WorkOrderService;
use App\Support\Spreadsheet;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * @param  list<list<mixed>>  $rows  baris pertama = header
 */
function xlsxFile(array $rows): string
{
    $path = Spreadsheet::tempPath('xlsx');
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
}

/** @param array<string, mixed> $f */
function readyMadeVariant(array $f, ?float $packing = null): ProductVariant
{
    $model = ProductModel::create(['code' => 'RM', 'name' => 'Ready Made', 'category_id' => $f['category']->id, 'kind' => 'beli_jadi']);

    return ProductVariant::create(['model_id' => $model->id, 'sku' => 'RM-1', 'name' => 'Ready Made 1', 'packing_cost' => $packing, 'is_active' => true]);
}

/** @param array<string, mixed> $f */
function receiveReadyMade(array $f, ProductVariant $variant, float $unitCost, string $date): void
{
    app(InventoryService::class)->createGoodsReceipt(
        ['receipt_no' => 'BM-'.$date.'-'.$unitCost, 'warehouse_id' => $f['warehouse']->id, 'received_at' => $date, 'source' => GoodsReceiptSource::BeliJadi],
        [['variant_id' => $variant->id, 'qty' => 10, 'unit_cost' => $unitCost]],
    );
    app(PricingService::class)->syncForReadyMade([$variant->id], $date);
}

it('prices ready made products from purchase price plus packing and the ready made margin', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'packing_cost_default'], ['value' => 1000]);
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 100]);
    $variant = readyMadeVariant($f);

    receiveReadyMade($f, $variant, 40000, '2026-10-01');

    $cost = $variant->costs()->first();
    expect($cost->source)->toBe(VariantCostSource::Purchase)
        ->and((float) $cost->material_cost)->toBe(40000.0)
        ->and((float) $cost->labor_cost)->toBe(1000.0);

    // (40.000 + 1.000) x 1,05 = 43.050 -> dibulatkan ke atas 100 = 43.100
    $price = $variant->priceHistories()->first();
    expect((float) $price->base_price)->toBe(43100.0)
        ->and($price->effective_from->toDateString())->toBe('2026-10-01')
        ->and((float) VariantPricing::find($variant->id)->proposed_price)->toBe(43100.0);
});

it('uses the variant packing cost and the latest purchase price for a new receipt date', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 100]);
    $variant = readyMadeVariant($f, packing: 500);

    receiveReadyMade($f, $variant, 40000, '2026-10-01');
    receiveReadyMade($f, $variant, 50000, '2026-10-10');

    $prices = $variant->priceHistories()->orderBy('effective_from')->pluck('base_price')->map(fn ($price): float => (float) $price)->all();

    // (40.000 + 500) x 1,05 = 42.525 -> 42.600; (50.000 + 500) x 1,05 = 53.025 -> 53.100
    expect($prices)->toBe([42600.0, 53100.0]);
});

it('skips the ready made price with a clear reason when no purchase price exists', function () {
    $f = businessFixture();
    $variant = readyMadeVariant($f);

    $result = app(PricingService::class)->syncVariants([$variant]);

    expect($result['synced'])->toBe(0)
        ->and($result['skipped'][0])->toContain('belum punya harga beli');
});

it('records the tailor on a work order when its first wage payment is saved', function () {
    $f = businessFixture();
    $sewing = LaborType::create(['code' => 'JAHIT', 'name' => 'Jahit']);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $sewing->id, 'labor_rate' => 5000, 'qty_per_unit' => 1]);
    $workOrder = app(WorkOrderService::class)->create(
        ['warehouse_id' => $f['materialWarehouse']->id, 'goods_warehouse_id' => $f['warehouse']->id, 'cut_date' => '2026-10-01'],
        [['variant_id' => $f['variant']->id, 'qty_cut' => 10]],
    );
    $tailor = Tailor::create(['name' => 'Pak Joko']);

    expect($workOrder->tailor_id)->toBeNull()
        ->and($workOrder->laborDue())->toBe(50000.0);

    TailorPayment::create(['tailor_id' => $tailor->id, 'work_order_id' => $workOrder->id, 'paid_at' => '2026-10-04', 'amount' => 30000]);

    expect($workOrder->fresh()->tailor_id)->toBe($tailor->id)
        ->and($workOrder->fresh()->laborDue())->toBe(20000.0);
});

it('imports only the sheet of the current resource from a full catalog workbook', function () {
    businessFixture();
    $path = app(CatalogSpreadsheet::class)->export('xlsx');

    $result = app(CatalogImporter::class)->import($path, 'katalog.xlsx', ['products']);

    expect($result['sheets'])->toBe(['Produk'])
        ->and($result['errors'])->toBe([]);
    unlink($path);
});

it('exports and re-imports a single catalog sheet with the packing cost column', function () {
    $f = businessFixture();
    $f['variant']->update(['packing_cost' => 750]);
    $path = app(CatalogSpreadsheet::class)->export('xlsx', 'products');

    $f['variant']->update(['packing_cost' => null]);
    $result = app(CatalogImporter::class)->import($path, 'produk.xlsx', ['products']);

    expect($result['errors'])->toBe([])
        ->and((float) $f['variant']->fresh()->packing_cost)->toBe(750.0);
    unlink($path);
});

it('round trips own-model imports for models, owners, channels and price tiers', function () {
    businessFixture();
    PriceTier::create(['code' => 'GOLD', 'name' => 'Gold', 'adjustment_pct' => 0.1]);

    $models = app(ProductModelImport::class)->import(xlsxFile([
        ['KODE MODEL', 'NAMA MODEL', 'KATEGORI', 'JENIS PRODUK', 'MARGIN MODEL %'],
        ['MODEL', 'Model Baru', 'Clothes', 'Beli Jadi', '10%'],
        ['NEW', '', 'Topi', '', ''],
    ]), 'model.xlsx');
    $owners = app(OwnerImport::class)->import(xlsxFile([
        ['KODE PEMILIK', 'NAMA PEMILIK', 'TIER', 'TIER BERLAKU MULAI'],
        ['OWN', 'Owner Baru', 'GOLD', '2026-10-01'],
    ]), 'pemilik.xlsx');
    $channels = app(ChannelImport::class)->import(xlsxFile([
        ['KODE CHANNEL', 'NAMA CHANNEL', 'JENIS CHANNEL', 'FEE ADMIN %', 'IKLAN %', 'FEE BERLAKU MULAI'],
        ['SHOP', 'Shopee', 'Marketplace', '8', '2', '2026-10-01'],
    ]), 'channel.xlsx');
    $tiers = app(PriceTierImport::class)->import(xlsxFile([
        ['KODE TIER', 'NAMA TIER', 'PENYESUAIAN %'],
        ['GOLD', 'Gold', '25'],
    ]), 'tier.xlsx');

    expect([$models['errors'], $owners['errors'], $channels['errors'], $tiers['errors']])->toBe([[], [], [], []]);
    expect(ProductModel::where('code', 'MODEL')->first())
        ->name->toBe('Model Baru')
        ->kind->value->toBe('beli_jadi')
        ->and((float) ProductModel::where('code', 'MODEL')->value('default_margin_pct'))->toBe(0.1)
        ->and(ProductModel::where('code', 'NEW')->first()->category->name)->toBe('Topi')
        ->and(Owner::where('code', 'OWN')->first()->tierHistories()->count())->toBe(1)
        ->and(Channel::where('code', 'SHOP')->first()->feeRates()->count())->toBe(1)
        ->and((float) PriceTier::where('code', 'GOLD')->value('adjustment_pct'))->toBe(0.25);

    // Export = layout impor: diimpor ulang tanpa perubahan.
    foreach ([ProductModelImport::class, OwnerImport::class, ChannelImport::class, PriceTierImport::class] as $importer) {
        $path = app($importer)->export();
        $again = app($importer)->import($path, 'ulang.xlsx');
        unlink($path);

        expect($again['errors'])->toBe([])
            ->and(collect($again['stats'])->sum('updated'))->toBe(0);
    }
});

it('pays tailors only the JAHIT labor of the recipe, not cutting or other labor', function () {
    $f = businessFixture();
    $cut = LaborType::create(['code' => 'POTONG', 'name' => 'Potong']);
    $sew = LaborType::create(['code' => 'JAHIT', 'name' => 'Jahit']);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $cut->id, 'labor_rate' => 1000, 'qty_per_unit' => 1]);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $sew->id, 'labor_rate' => 4000, 'qty_per_unit' => 1]);

    $workOrder = app(WorkOrderService::class)->create(
        ['warehouse_id' => $f['materialWarehouse']->id, 'goods_warehouse_id' => $f['warehouse']->id, 'cut_date' => '2026-10-01'],
        [['variant_id' => $f['variant']->id, 'qty_cut' => 10]],
    );

    expect((float) $workOrder->items()->sole()->labor_cost_snapshot)->toBe(5000.0)
        ->and($workOrder->laborDue())->toBe(40000.0);
});

it('pays the cutter POTONG labor per cut piece and records the cutter on the work order', function () {
    $f = businessFixture();
    $cut = LaborType::create(['code' => 'POTONG', 'name' => 'Potong']);
    $sew = LaborType::create(['code' => 'JAHIT', 'name' => 'Jahit']);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $cut->id, 'labor_rate' => 1000, 'qty_per_unit' => 1]);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $sew->id, 'labor_rate' => 4000, 'qty_per_unit' => 1]);
    $workOrder = app(WorkOrderService::class)->create(
        ['warehouse_id' => $f['materialWarehouse']->id, 'goods_warehouse_id' => $f['warehouse']->id, 'cut_date' => '2026-10-01'],
        [['variant_id' => $f['variant']->id, 'qty_cut' => 10]],
    );
    $cutter = Tailor::create(['name' => 'Mas Potong']);

    expect($workOrder->laborDue(null, WageType::Potong))->toBe(10000.0)
        ->and($workOrder->laborDue())->toBe(40000.0);

    TailorPayment::create(['tailor_id' => $cutter->id, 'work_order_id' => $workOrder->id, 'wage_type' => 'potong', 'paid_at' => '2026-10-04', 'amount' => 10000]);

    $workOrder->refresh();
    expect($workOrder->cutter_id)->toBe($cutter->id)
        ->and($workOrder->tailor_id)->toBeNull()
        ->and($workOrder->laborDue(null, WageType::Potong))->toBe(0.0)
        ->and($workOrder->laborDue())->toBe(40000.0);
});

it('pays wages by the labor type "dibayar sebagai" setting, whatever its code', function () {
    $f = businessFixture();
    $sew = LaborType::create(['code' => 'JHT-01', 'name' => 'Ongkos tukang', 'wage_type' => 'jahit']);
    $cut = LaborType::create(['code' => 'PTG', 'name' => 'Potong kain']); // ditebak dari nama
    $embroidery = LaborType::create(['code' => 'BRD', 'name' => 'Bordir']);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $sew->id, 'labor_rate' => 4000, 'qty_per_unit' => 1]);

    expect($cut->wage_type)->toBe(WageType::Potong)
        ->and($embroidery->wage_type)->toBeNull()
        ->and(app(CostingService::class)->wageWarnings($f['bom']->refresh()))->toHaveCount(1);

    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $cut->id, 'labor_rate' => 1000, 'qty_per_unit' => 1]);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $embroidery->id, 'labor_rate' => 3000, 'qty_per_unit' => 1]);

    $workOrder = app(WorkOrderService::class)->create(
        ['warehouse_id' => $f['materialWarehouse']->id, 'goods_warehouse_id' => $f['warehouse']->id, 'cut_date' => '2026-10-01'],
        [['variant_id' => $f['variant']->id, 'qty_cut' => 10]],
    );

    expect(app(CostingService::class)->wageWarnings($f['bom']->refresh()))->toBe([])
        ->and((float) $workOrder->items()->sole()->labor_cost_snapshot)->toBe(8000.0)
        ->and($workOrder->laborTotal())->toBe(40000.0)
        ->and($workOrder->laborTotal(WageType::Potong))->toBe(10000.0);
});

it('imports ready made receipts with QTY 0 to record the purchase price without adding stock', function () {
    $f = businessFixture();
    $variant = readyMadeVariant($f);
    $ledger = app(StockLedger::class);

    $result = app(GoodsReceiptImport::class)->import(xlsxFile([
        ['NO DOKUMEN', 'TANGGAL', 'GUDANG', 'SUMBER', 'SKU VARIAN', 'QTY', 'HARGA SATUAN'],
        ['BM-HARGA-1', '2026-08-01', $f['warehouse']->code, 'Beli Jadi', 'RM-1', 0, 20000],
    ]), 'harga.xlsx');

    expect($result['errors'])->toBe([])
        ->and(GoodsReceiptItem::query()->sole()->qty)->toBe(0)
        ->and($ledger->stockQty($variant->id, $f['warehouse']->id))->toBe(0)
        ->and(app(CostingService::class)->latestPurchase($variant, '2026-08-05')['unit_cost'])->toBe(20000.0);
});

it('sets the qty of an existing receipt to 0, reversing its stock but keeping the purchase price', function () {
    $f = businessFixture();
    $variant = readyMadeVariant($f);
    receiveReadyMade($f, $variant, 20000, '2026-08-01'); // qty 10
    $ledger = app(StockLedger::class);
    $receipt = GoodsReceipt::query()->sole();

    expect($ledger->stockQty($variant->id, $f['warehouse']->id))->toBe(10);

    expect(app(InventoryService::class)->zeroGoodsReceiptQty($receipt))->toBe(1)
        ->and(app(InventoryService::class)->zeroGoodsReceiptQty($receipt->fresh()))->toBe(0); // aman diulang

    expect($ledger->stockQty($variant->id, $f['warehouse']->id))->toBe(0)
        ->and($receipt->items()->sole()->qty)->toBe(0)
        ->and(app(CostingService::class)->latestPurchase($variant, '2026-08-05')['unit_cost'])->toBe(20000.0);
});
