<?php

use App\Enums\GoodsReceiptSource;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\SupplierBills;
use App\Models\Color;
use App\Models\GoodsReceipt;
use App\Models\KonveksiMonthlyProfit;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\SalesOrderItem;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use App\Models\SupplierWholesalePrice;
use App\Models\User;
use App\Models\VariantCost;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderService;
use App\Services\SupplierBilling;
use App\Services\SupplierStatement;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/** @return array<string, mixed> */
function supplierFixture(): array
{
    $f = businessFixture();
    $model = ProductModel::create(['code' => 'VK', 'name' => 'Peci Velkro Bordir', 'category_id' => $f['category']->id, 'kind' => 'beli_jadi']);
    $variant = ProductVariant::create(['model_id' => $model->id, 'sku' => 'VK-Hitam', 'name' => 'Peci Velkro Hitam', 'is_active' => true]);

    return [...$f, 'rmModel' => $model, 'rm' => $variant, 'rudi' => Supplier::create(['name' => 'Rudi']), 'andi' => Supplier::create(['name' => 'Andi']), 'rendi' => Supplier::create(['name' => 'Rendi'])];
}

/** @param array<string, mixed> $f */
function receiveFrom(array $f, Supplier $supplier, int $qty, float $cost, string $date): void
{
    app(InventoryService::class)->createGoodsReceipt(
        ['receipt_no' => 'BM-'.$supplier->name.'-'.$date, 'warehouse_id' => $f['warehouse']->id, 'received_at' => $date, 'source' => GoodsReceiptSource::BeliJadi, 'supplier_id' => $supplier->id],
        [['variant_id' => $f['rm']->id, 'qty' => $qty, 'unit_cost' => $cost]],
    );
    app(PricingService::class)->syncForReadyMade([$f['rm']->id], $date);
}

/** @param array<string, mixed> $f */
function sellReadyMade(array $f, int $qty, string $date): SalesOrderItem
{
    return app(SalesOrderService::class)->create(
        ['store_id' => $f['store']->id, 'warehouse_id' => $f['warehouse']->id, 'order_date' => $date],
        [['variant_id' => $f['rm']->id, 'qty' => $qty, 'revenue' => $qty * 100000]],
    )->items()->sole();
}

/** @return array<string, float> */
function billsBySupplier(): array
{
    return SupplierBillLine::query()->whereNotNull('supplier_id')->with('supplier')->get()
        ->groupBy('supplier.name')->map(fn ($lines): float => (float) $lines->sum('amount'))->sortKeys()->all();
}

it('bills suppliers FIFO from the oldest lot when an order is completed', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 3, 40000, '2026-08-01');
    receiveFrom($f, $f['andi'], 50, 42000, '2026-08-02');

    sellReadyMade($f, 5, '2026-08-03');

    expect(billsBySupplier())->toBe(['Andi' => 84000.0, 'Rudi' => 120000.0])
        ->and(StockLot::query()->orderBy('id')->pluck('qty_remaining')->all())->toBe([0, 48]);
});

it('keeps negative stock unallocated until the next ready made receipt arrives', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 1, 40000, '2026-08-01');

    sellReadyMade($f, 4, '2026-08-03');
    expect((int) SupplierBillLine::query()->whereNull('supplier_id')->sum('qty'))->toBe(3)
        ->and(app(SupplierStatement::class)->unallocated()['qty'])->toBe(3);

    receiveFrom($f, $f['andi'], 10, 42000, '2026-08-05');

    expect(SupplierBillLine::query()->whereNull('supplier_id')->count())->toBe(0)
        ->and(billsBySupplier())->toBe(['Andi' => 126000.0, 'Rudi' => 40000.0])
        ->and(StockLot::query()->where('supplier_id', $f['andi']->id)->value('qty_remaining'))->toBe(7);
});

it('reduces the bill on restocked returns and removes it on cancellation', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 10, 40000, '2026-08-01');
    $item = sellReadyMade($f, 4, '2026-08-03');

    app(SalesOrderService::class)->registerReturn($item, ['returned_at' => '2026-08-04', 'qty' => 1, 'restock' => true, 'credit_reseller' => true, 'reason' => 'lainnya']);
    expect(billsBySupplier())->toBe(['Rudi' => 120000.0])
        ->and(StockLot::query()->value('qty_remaining'))->toBe(7);

    $other = sellReadyMade($f, 2, '2026-08-05');
    app(SalesOrderService::class)->cancel($other->salesOrder);
    expect(billsBySupplier())->toBe(['Rudi' => 120000.0])
        ->and(StockLot::query()->value('qty_remaining'))->toBe(7);
});

it('prices embroidery rows at the wholesale tier automatically once QTY reaches 10 or 20', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rendi'], 100, 15000, '2026-08-01');
    SupplierWholesalePrice::create(['supplier_id' => $f['rendi']->id, 'model_id' => $f['rmModel']->id, 'min_qty' => 10, 'unit_price' => 13000, 'effective_from' => '2026-08-01']);
    SupplierWholesalePrice::create(['supplier_id' => $f['rendi']->id, 'model_id' => $f['rmModel']->id, 'min_qty' => 20, 'unit_price' => 12000, 'effective_from' => '2026-08-01']);
    $import = function (string $csv) use ($f): array {
        $path = tempnam(sys_get_temp_dir(), 'grosir-').'.csv';
        file_put_contents($path, $csv);
        try {
            return app(SalesOrderImporter::class)->import($path, 'grosir.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'website']);
        } finally {
            unlink($path);
        }
    };

    $import('TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN
2026-08-10,Shop,VK-Hitam,25,500000,A1
2026-08-10,Shop,VK-Hitam,12,240000,A2
2026-08-10,Shop,VK-Hitam,8,160000,A3
');

    $items = SalesOrderItem::query()->orderBy('id')->get();
    $normalPrice = (float) $items[2]->reseller_unit_price;
    expect($items->map(fn (SalesOrderItem $item): array => [$item->qty, $item->is_consignment, (float) $item->reseller_unit_price])->all())
        ->toBe([[25, true, 12000.0], [12, true, 13000.0], [8, false, $normalPrice]])
        ->and((float) SupplierBillLine::query()->where('is_consignment', true)->sum('amount'))->toBe(25 * 12000.0 + 12 * 13000.0)
        ->and((float) SupplierBillLine::query()->where('is_consignment', false)->sum('amount'))->toBe(8 * 15000.0)
        ->and(StockLot::query()->value('qty_remaining'))->toBe(55);

    // Laba konveksi tidak menghitung titipan grosir.
    $month = KonveksiMonthlyProfit::query()->whereDate('month', '2026-08-01')->first();
    expect((float) $month->net_sales)->toBe(8 * $normalPrice);

    // Koreksi qty lewat import ulang: A3 naik ke 10 pcs -> pindah ke harga grosir; A1 turun ke 5 -> harga normal.
    $import('TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN
2026-08-10,Shop,VK-Hitam,5,100000,A1
2026-08-10,Shop,VK-Hitam,10,200000,A3
');

    $items = SalesOrderItem::query()->orderBy('id')->get();
    expect($items->map(fn (SalesOrderItem $item): array => [$item->qty, $item->is_consignment, (float) $item->reseller_unit_price])->all())
        ->toBe([[5, false, $normalPrice], [12, true, 13000.0], [10, true, 13000.0]])
        ->and((float) SupplierBillLine::query()->where('is_consignment', true)->sum('amount'))->toBe(22 * 13000.0)
        ->and(StockLot::query()->value('qty_remaining'))->toBe(73);
});

it('counts the wholesale embroidery quantity per SKU per order, never across colours or sizes', function () {
    $f = supplierFixture();
    $white = ProductVariant::create(['model_id' => $f['rmModel']->id, 'color_id' => Color::create(['code' => 'PT', 'name' => 'Putih'])->id, 'sku' => 'VK-Putih', 'name' => 'Peci Velkro Putih', 'is_active' => true]);
    receiveFrom($f, $f['rendi'], 100, 15000, '2026-08-01');
    app(InventoryService::class)->createGoodsReceipt(
        ['receipt_no' => 'BM-PUTIH', 'warehouse_id' => $f['warehouse']->id, 'received_at' => '2026-08-01', 'source' => GoodsReceiptSource::BeliJadi, 'supplier_id' => $f['rendi']->id],
        [['variant_id' => $white->id, 'qty' => 100, 'unit_cost' => 15000]],
    );
    app(PricingService::class)->syncForReadyMade([$white->id], '2026-08-01');
    SupplierWholesalePrice::create(['supplier_id' => $f['rendi']->id, 'model_id' => $f['rmModel']->id, 'min_qty' => 10, 'unit_price' => 13000, 'effective_from' => '2026-08-01']);
    SupplierWholesalePrice::create(['supplier_id' => $f['rendi']->id, 'model_id' => $f['rmModel']->id, 'min_qty' => 20, 'unit_price' => 12000, 'effective_from' => '2026-08-01']);
    $import = function (string $rows) use ($f): array {
        $path = tempnam(sys_get_temp_dir(), 'grosir-').'.csv';
        file_put_contents($path, "Nomor Pesanan,Nama Panggilan Toko BigSeller,Waktu Pesanan Dibuat,SKU,Jumlah,Subtotal Produk\n".$rows);
        try {
            return app(SalesOrderImporter::class)->import($path, 'grosir.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'bigseller']);
        } finally {
            unlink($path);
        }
    };
    $lines = fn (): array => SalesOrderItem::query()->with(['salesOrder', 'variant'])->orderBy('id')->get()
        ->map(fn (SalesOrderItem $item): string => "{$item->salesOrder->order_no} {$item->variant->sku} {$item->qty} ".($item->is_consignment ? 'grosir '.(int) $item->reseller_unit_price : 'normal'))->all();

    // P1: Hitam 6 + Putih 5 = beda SKU => tidak grosir. Putih 10 di P2 => grosir. P3: Hitam 4 + 6 (SKU sama, 2 baris) => grosir.
    $result = $import(
        "P1,Shop,10 Agu 2026 10:00,VK-Hitam,6,90000\nP1,Shop,10 Agu 2026 10:00,VK-Putih,5,75000\n"
        ."P2,Shop,10 Agu 2026 11:00,VK-Putih,10,130000\n"
        ."P3,Shop,10 Agu 2026 12:00,VK-Hitam,4,60000\nP3,Shop,10 Agu 2026 12:00,VK-Hitam,6,90000\n"
    );

    expect($result['errors'])->toBe([])
        ->and($lines())->toBe([
            'P1 VK-Hitam 6 normal', 'P1 VK-Putih 5 normal',
            'P2 VK-Putih 10 grosir 13000',
            'P3 VK-Hitam 4 grosir 13000', 'P3 VK-Hitam 6 grosir 13000',
        ])
        ->and((float) SupplierBillLine::query()->where('is_consignment', true)->sum('amount'))->toBe(20 * 13000.0)
        ->and(SupplierBillLine::query()->where('is_consignment', true)->pluck('supplier_id')->unique()->values()->all())->toBe([$f['rendi']->id]);

    // Koreksi lewat file lain: baris kedua P3 turun ke 5 => total Hitam 9, baris pertama (tidak ada di file) ikut harga normal.
    $corrected = $import("P3,Shop,10 Agu 2026 12:00,VK-Hitam,4,60000\nP3,Shop,10 Agu 2026 12:00,VK-Hitam,5,75000\n");
    expect($corrected['errors'])->toBe([])
        ->and(array_slice($lines(), 3))->toBe(['P3 VK-Hitam 4 normal', 'P3 VK-Hitam 5 normal']);

    // P4 terpisah di dua file, SKU sama: total 25 => grosir >= 20 untuk kedua baris.
    $import("P4,Shop,11 Agu 2026 10:00,VK-Putih,15,225000\n");
    $import("P4,Shop,11 Agu 2026 10:00,VK-Putih,15,225000\nP4,Shop,11 Agu 2026 10:00,VK-Putih,10,150000\n");
    expect(array_slice($lines(), -2))->toBe(['P4 VK-Putih 15 grosir 12000', 'P4 VK-Putih 10 grosir 12000']);
});

it('rebuilds bills from August while earlier sales still consume older lots', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 5, 40000, '2026-07-01');
    receiveFrom($f, $f['andi'], 10, 42000, '2026-07-15');
    sellReadyMade($f, 5, '2026-07-20');
    sellReadyMade($f, 2, '2026-08-02');
    SupplierBillLine::query()->delete();

    $result = app(SupplierBilling::class)->rebuild('2026-08-01');

    expect($result['lots'])->toBe(2)
        ->and(billsBySupplier())->toBe(['Andi' => 84000.0]);
});

it('shows the supplier statement per period and reduces it by payments', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 10, 40000, '2026-08-01');
    sellReadyMade($f, 4, '2026-08-03');
    SupplierPayment::create(['supplier_id' => $f['rudi']->id, 'paid_at' => '2026-08-10', 'amount' => 100000]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    expect(app(SupplierStatement::class)->balance($f['rudi']))->toBe(60000.0);

    $this->get('/administrator/tagihan-supplier')->assertOk()->assertSee('Tagihan Supplier');
    Livewire::test(SupplierBills::class)
        ->set('tableFilters.period.from', '2026-08-01')
        ->set('tableFilters.period.until', '2026-08-31')
        ->assertCanSeeTableRecords([$f['rudi']])
        ->assertTableColumnStateSet('billed_normal', 160000, $f['rudi'])
        ->assertTableColumnStateSet('closing_balance', 60000.0, $f['rudi'])
        ->assertCanNotSeeTableRecords([$f['andi']]);

    $this->get('/administrator/tagihan-supplier/rincian')->assertOk();
    $this->get('/administrator/pembayaran-supplier')->assertOk();
    $this->get('/administrator/harga-grosir-bordir')->assertOk();
});

it('uses ready made lots without a supplier FIFO without billing anyone and restores them on cancel', function () {
    $f = supplierFixture();
    app(InventoryService::class)->createGoodsReceipt(
        ['receipt_no' => 'BM-TUNAI', 'warehouse_id' => $f['warehouse']->id, 'received_at' => '2026-08-01', 'source' => GoodsReceiptSource::BeliJadi],
        [['variant_id' => $f['rm']->id, 'qty' => 2, 'unit_cost' => 40000]],
    );
    receiveFrom($f, $f['rudi'], 10, 41000, '2026-08-02');

    $item = sellReadyMade($f, 3, '2026-08-03');
    expect(billsBySupplier())->toBe(['Rudi' => 41000.0])
        ->and(app(SupplierStatement::class)->unallocated()['qty'])->toBe(0);

    app(SalesOrderService::class)->cancel($item->salesOrder);
    expect(StockLot::query()->orderBy('id')->pluck('qty_remaining')->all())->toBe([2, 10])
        ->and(SupplierBillLine::query()->count())->toBe(0);
});

it('moves lots and their bills when the supplier of a ready made receipt is corrected', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 10, 40000, '2026-08-01');
    sellReadyMade($f, 2, '2026-08-03');

    GoodsReceipt::query()->first()->update(['supplier_id' => $f['andi']->id]);

    expect(billsBySupplier())->toBe(['Andi' => 80000.0])
        ->and(StockLot::query()->value('supplier_id'))->toBe($f['andi']->id);
});

it('records packing edited from the variant form as dated history once dated packing exists', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rudi'], 10, 40000, '2026-08-01');
    app(PricingService::class)->changePrice($f['rm'], ['packing_cost' => 1000], '2026-08-01');

    $f['rm']->refresh()->update(['packing_cost' => 2500]);

    expect(app(CostingService::class)->packingCostFor($f['rm'], today()))->toBe(2500.0)
        ->and(app(CostingService::class)->packingCostFor($f['rm'], '2026-08-02'))->toBe(1000.0);
});

it('keeps billing the supplier for damaged or lost returns, wholesale consignment included', function () {
    $f = supplierFixture();
    receiveFrom($f, $f['rendi'], 50, 15000, '2026-08-01');
    SupplierWholesalePrice::create(['supplier_id' => $f['rendi']->id, 'model_id' => $f['rmModel']->id, 'min_qty' => 10, 'unit_price' => 13000, 'effective_from' => '2026-08-01']);
    $item = app(SalesOrderService::class)->create(
        ['store_id' => $f['store']->id, 'warehouse_id' => $f['warehouse']->id, 'order_date' => '2026-08-03'],
        [['variant_id' => $f['rm']->id, 'qty' => 12, 'consignment_price' => 13000]],
    )->items()->sole();
    $normal = sellReadyMade($f, 2, '2026-08-03');

    app(SalesOrderService::class)->registerReturn($item, ['returned_at' => '2026-08-05', 'qty' => 2, 'restock' => false, 'credit_reseller' => true, 'reason' => 'lainnya']);
    app(SalesOrderService::class)->registerReturn($normal, ['returned_at' => '2026-08-05', 'qty' => 1, 'restock' => false, 'credit_reseller' => true, 'reason' => 'lainnya']);

    // Barang rusak/hilang tetap ditagih: titipan 12 x 13.000 + normal 2 x 15.000.
    expect(billsBySupplier())->toBe(['Rendi' => 156000.0 + 30000.0])
        ->and(StockLot::query()->value('qty_remaining'))->toBe(36);
});

// ---------------------------------------------------------------- mode berdasarkan barang keluar (tanpa stok)

/**
 * Harga beli (HPP beli jadi tanpa packing) + harga reseller berlaku mulai $date, tanpa Barang Masuk.
 *
 * @param  array<string, mixed>  $f
 */
function readyMadePriceFrom(array $f, float $purchase, string $date): void
{
    $cost = VariantCost::create(['variant_id' => $f['rm']->id, 'effective_from' => $date, 'material_cost' => $purchase, 'labor_cost' => 3000, 'packing_cost' => 3000, 'source' => 'manual']);
    ResellerPriceHistory::create(['variant_id' => $f['rm']->id, 'effective_from' => $date, 'base_price' => $purchase + 10000, 'variant_cost_id' => $cost->id, 'source' => 'manual']);
}

function billBySales(): void
{
    Setting::updateOrCreate(['key' => 'supplier_billing_mode'], ['value' => SupplierBilling::MODE_SALES]);
}

it('bills goods out to the model supplier at the purchase price of the order date, without any stock', function () {
    $f = supplierFixture();
    billBySales();
    $f['rmModel']->update(['supplier_id' => $f['rudi']->id]);
    readyMadePriceFrom($f, 20000, '2026-08-01');
    readyMadePriceFrom($f, 25000, '2026-09-01');

    sellReadyMade($f, 3, '2026-08-10');
    sellReadyMade($f, 2, '2026-09-05');

    expect(billsBySupplier())->toBe(['Rudi' => 110000.0]) // 3 x 20.000 + 2 x 25.000, packing tidak ditagihkan
        ->and(StockLot::query()->count())->toBe(0);
});

it('waits for the model supplier, then moves the goods-out bill to it automatically', function () {
    $f = supplierFixture();
    billBySales();
    readyMadePriceFrom($f, 20000, '2026-08-01');
    $item = sellReadyMade($f, 4, '2026-08-10');

    expect(app(SupplierStatement::class)->unallocated()['qty'])->toBe(4)
        ->and(billsBySupplier())->toBe([]);

    $f['rmModel']->update(['supplier_id' => $f['andi']->id]);
    expect(billsBySupplier())->toBe(['Andi' => 80000.0]);

    app(SalesOrderService::class)->cancel($item->salesOrder);
    expect(SupplierBillLine::query()->count())->toBe(0);
});

it('switches to goods-out billing on rebuild and rejects an unknown mode', function () {
    $f = supplierFixture();
    $f['rmModel']->update(['supplier_id' => $f['rudi']->id]);
    readyMadePriceFrom($f, 20000, '2026-08-01');
    sellReadyMade($f, 2, '2026-08-10'); // mode stok: tanpa lot = belum teralokasi

    expect(billsBySupplier())->toBe([]);

    $result = app(SupplierBilling::class)->rebuild('2026-08-01', SupplierBilling::MODE_SALES);

    expect($result['lines'])->toBe(1)
        ->and($result['unallocated'])->toBe(0)
        ->and(billsBySupplier())->toBe(['Rudi' => 40000.0])
        ->and(fn () => Setting::updateOrCreate(['key' => 'supplier_billing_mode'], ['value' => 'apa']))->toThrow(BusinessRuleException::class);
});
