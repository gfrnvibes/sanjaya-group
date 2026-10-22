<?php

use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Services\InvoiceService;
use App\Services\SalesOrderImporter;
use App\Services\SalesOrderService;
use App\Services\SalesOrderSpreadsheet;
use App\Services\StockLedger;

/** @param array<string,mixed> $fixture @return array<string,mixed> */
function importSalesCsv(array $fixture, string $contents, string $fileName = 'sales.csv'): array
{
    $path = tempnam(sys_get_temp_dir(), 'sales-');
    file_put_contents($path, $contents);
    try {
        return app(SalesOrderImporter::class)->import($path, $fileName, ['warehouse_id' => $fixture['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);
    } finally {
        unlink($path);
    }
}

it('corrects a BigSeller transaction by its explicit number without adding the full quantity twice', function () {
    $f = businessFixture();
    $header = "TANGGAL,NAMA TOKO,NOMOR SKU TOKO,PENJUALAN PRODUK VALID,DANA PENJUALAN PRODUK VALID,NO PESANAN\n";
    $first = importSalesCsv($f, $header."2026-10-01,Shop,001234,10,1500,BS1\n");
    $corrected = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800,BS1\n");
    $again = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800,BS1\n");
    expect($first['created'])->toBe(1);
    expect($corrected['errors'])->toBe([]);
    expect($again['skipped'])->toBe(1);
    $this->assertDatabaseCount('sales_orders', 1);
    $this->assertDatabaseHas('sales_order_items', ['qty' => 12, 'revenue' => 1800]);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(88);
});

it('does not mistake another order for a correction just because its date store and SKU are the same', function () {
    $f = businessFixture();
    $header = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,10,1500\n");
    $result = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800\n");
    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(1);
    $this->assertDatabaseCount('sales_orders', 2);
    $this->assertDatabaseHas('sales_order_items', ['qty' => 10]);
    $this->assertDatabaseHas('sales_order_items', ['qty' => 12]);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(78);
});

it('bills both identical product rows but does not add them again when the same file is renamed and uploaded again', function (string $header) {
    $f = businessFixture(0);
    $csv = $header."2026-10-01,Shop,001234,1,150\n2026-10-01,Shop,001234,1,150\n";

    $first = importSalesCsv($f, $csv);
    $again = importSalesCsv($f, $csv, 'renamed.csv');
    $invoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    app(InvoiceService::class)->issue($invoice);

    expect($first['errors'])->toBe([]);
    expect($first['created'])->toBe(2);
    expect($again['errors'])->toBe([]);
    expect($again['created'])->toBe(0);
    expect($again['skipped'])->toBe(2);
    $this->assertDatabaseCount('sales_orders', 2);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
    expect($invoice->items()->sum('qty'))->toBe(2);
    expect($invoice->netTotal())->toBe(200.0);
})->with([
    'manual' => "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO\n",
    'BigSeller' => "TANGGAL,NAMA TOKO,NOMOR SKU TOKO,PENJUALAN PRODUK VALID,DANA PENJUALAN PRODUK VALID\n",
]);

it('keeps distinct manual transactions with stable numbers separate and allows unbilled corrections', function () {
    $f = businessFixture();
    $header = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,10,1500,A1\n2026-10-01,Shop,001234,10,1500,A2\n");
    $result = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800,A1\n");
    expect($result['errors'])->toBe([]);
    $this->assertDatabaseCount('sales_orders', 2);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(78);
});

it('imports a new numbered order for the same product after a previous order has been invoiced', function () {
    $f = businessFixture(0);
    $header = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,1,150,A1\n");
    $firstInvoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    app(InvoiceService::class)->issue($firstInvoice);

    $result = importSalesCsv($f, $header."2026-10-01,Shop,001234,1,150,A2\n");
    $secondInvoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(1);
    expect($firstInvoice->netTotal())->toBe(100.0);
    expect($secondInvoice->netTotal())->toBe(100.0);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
});

it('does not discard identical unnumbered orders merely because they appear in a different file', function () {
    $f = businessFixture(0);
    $header = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,1,150\n");

    $result = importSalesCsv($f, $header."2026-10-01,Shop,001234,1,150\n2026-10-01,Shop,001234,2,300\n", 'new-orders.csv');
    $invoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(2);
    $this->assertDatabaseCount('sales_orders', 3);
    expect($invoice->items()->sum('qty'))->toBe(4);
    expect($invoice->netTotal())->toBe(400.0);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-4);
});

it('rejects a repeated cancelled import rather than silently skipping it', function () {
    $f = businessFixture();
    $csv = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN\n2026-10-01,Shop,001234,10,1500,A1\n";
    importSalesCsv($f, $csv);
    app(SalesOrderService::class)->cancel(SalesOrder::query()->first());
    $result = importSalesCsv($f, $csv);
    expect($result['errors'][0])->toContain('sudah dibatalkan');
    $this->assertDatabaseCount('sales_orders', 1);
});

it('rejects corrections once an imported order has been invoiced', function () {
    $f = businessFixture();
    $header = "TANGGAL,NAMA TOKO,SKU VARIAN,QTY,PENDAPATAN TOKO,NO PESANAN\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,10,1500,A1\n");
    app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    $result = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800,A1\n");
    expect($result['errors'][0])->toContain('sudah ditagih');
    $this->assertDatabaseHas('sales_order_items', ['qty' => 10]);
});

it('retains a BigSeller correction identity when retrying a failed row from the failure workbook', function () {
    $f = businessFixture(10);
    $header = "TANGGAL,NAMA TOKO,NOMOR SKU TOKO,PENJUALAN PRODUK VALID,DANA PENJUALAN PRODUK VALID,NO PESANAN\n";
    importSalesCsv($f, $header."2026-10-01,Shop,001234,10,1500,BS1\n");
    $f['warehouse']->update(['is_active' => false]);
    $failed = importSalesCsv($f, $header."2026-10-01,Shop,001234,12,1800,BS1\n");
    expect($failed['errors'])->not->toBeEmpty();
    $path = app(SalesOrderSpreadsheet::class)->failures(SalesImportFailure::query()->where('batch_id', $failed['batch']->id));
    try {
        $f['warehouse']->update(['is_active' => true]);
        $result = app(SalesOrderImporter::class)->import($path, 'retry.xlsx', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);
        expect($result['errors'])->toBe([]);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseHas('sales_order_items', ['qty' => 12]);
        expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
    } finally {
        gc_collect_cycles();
        @unlink($path);
    }
});
