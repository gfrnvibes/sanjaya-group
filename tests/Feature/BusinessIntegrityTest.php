<?php

use App\Enums\MaterialMovementType;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Imports\MaterialStockCountImport;
use App\Models\Reseller;
use App\Models\ResellerInvoice;
use App\Models\StockOpname;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedger;
use App\Services\WorkOrderService;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

it('keeps sales billable when stock becomes negative and continues selling from a negative balance', function () {
    $f = businessFixture(2);
    fixtureOrder($f, 3);
    fixtureOrder($f, 2);

    $invoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    app(InvoiceService::class)->issue($invoice);

    $this->assertDatabaseCount('sales_orders', 2);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-3);
    expect($invoice->items()->sum('qty'))->toBe(5);
    expect($invoice->netTotal())->toBe(500.0);
    expect($invoice->status->value)->toBe('issued');
});

it('does not return cancelled stock twice when a caller holds a stale order', function () {
    $f = businessFixture();
    $order = fixtureOrder($f);
    $stale = $order->fresh();
    app(SalesOrderService::class)->cancel($order);
    expect(fn () => app(SalesOrderService::class)->cancel($stale))->toThrow(BusinessRuleException::class, 'sudah dibatalkan');
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(100);
    $this->assertDatabaseCount('stock_movements', 3);
});

it('rejects excess returns after another return has already been registered', function () {
    $f = businessFixture();
    $order = fixtureOrder($f, 10);
    $item = $order->items()->first();
    $data = ['returned_at' => '2026-10-02', 'qty' => 6, 'restock' => true, 'credit_reseller' => true, 'reason' => ReturnReason::Lainnya];
    app(SalesOrderService::class)->registerReturn($item, $data);
    expect(fn () => app(SalesOrderService::class)->registerReturn($item, $data))->toThrow(BusinessRuleException::class, 'melebihi sisa');
    $this->assertDatabaseCount('sales_returns', 1);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(96);
});

it('preserves sales after the physical count when posting an opname and prevents a second post', function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    $f = businessFixture();
    $opname = StockOpname::create(['warehouse_id' => $f['warehouse']->id, 'counted_at' => '2026-10-20 09:00:00', 'status' => 'draft']);
    $opname->items()->create(['variant_id' => $f['variant']->id, 'system_qty' => 100, 'counted_qty' => 98]);
    app(StockLedger::class)->moveStock($f['variant']->id, $f['warehouse']->id, -5, StockMovementType::Adjustment, movedAt: '2026-10-20 10:00:00');
    $stale = $opname->fresh();
    app(InventoryService::class)->postOpname($opname);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(93);
    expect(fn () => app(InventoryService::class)->postOpname($stale))->toThrow(BusinessRuleException::class, 'sudah diposting');
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(93);
});

it('requires all cut units to be accounted for and receives production only once', function () {
    $f = businessFixture();
    $wo = app(WorkOrderService::class)->create(['warehouse_id' => $f['materialWarehouse']->id, 'goods_warehouse_id' => $f['warehouse']->id, 'cut_date' => '2026-10-01'], [['variant_id' => $f['variant']->id, 'qty_cut' => 10]]);
    $item = $wo->items()->first();
    expect(fn () => app(WorkOrderService::class)->complete($wo, [$item->id => ['qty_good' => 8, 'qty_reject' => 0]], '2026-10-02'))->toThrow(BusinessRuleException::class, 'harus sama');
    expect($wo->fresh()->status->value)->toBe('cutting');
    $stale = $wo->fresh();
    app(WorkOrderService::class)->complete($wo, [$item->id => ['qty_good' => 8, 'qty_reject' => 2]], '2026-10-02');
    expect(fn () => app(WorkOrderService::class)->complete($stale, [$item->id => ['qty_good' => 8, 'qty_reject' => 2]], '2026-10-02'))->toThrow(BusinessRuleException::class, 'sudah selesai');
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(108);
});

it('prevents moving a store with historical sales to another reseller', function () {
    $f = businessFixture();
    fixtureOrder($f);
    $other = Reseller::create(['owner_id' => $f['owner']->id, 'name' => 'Other']);
    expect(fn () => $f['store']->update(['reseller_id' => $other->id]))->toThrow(BusinessRuleException::class, 'tidak boleh diganti');
    expect($f['store']->fresh()->reseller_id)->toBe($f['reseller']->id);
});

it('does not collect sales into an invoice that has already been issued', function () {
    $f = businessFixture();
    fixtureOrder($f);
    $invoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    $stale = $invoice->fresh();
    app(InvoiceService::class)->issue($invoice);
    expect(fn () => app(InvoiceService::class)->collect($stale))->toThrow(BusinessRuleException::class, 'Hanya tagihan draft');
});

it('allocates document numbers beyond four digits without reusing 10000', function () {
    $f = businessFixture();
    ResellerInvoice::create(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'invoice_no' => 'INV/202610/9999']);
    expect(DocumentNumber::next('INV/202610/', 'reseller_invoices', 'invoice_no'))->toBe('INV/202610/10000');
    expect(DocumentNumber::next('INV/202610/', 'reseller_invoices', 'invoice_no'))->toBe('INV/202610/10001');
});

it('keeps the lifecycle lock unavailable to a second database connection until commit', function () {
    $f = businessFixture();
    $order = fixtureOrder($f);
    app(SalesOrderService::class)->cancel($order);
    config(['database.connections.competing' => config('database.connections.pgsql')]);
    $other = DB::connection('competing');
    try {
        $other->beginTransaction();
        $lock = $other->selectOne("SELECT pg_try_advisory_xact_lock(hashtextextended('sales-documents', 0)) AS acquired");
        expect($lock->acquired)->toBeFalse();
        $stockLock = $other->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['stock:'.$f['variant']->id.':'.$f['warehouse']->id]);
        expect($stockLock->acquired)->toBeFalse();
    } finally {
        $other->rollBack();
        DB::disconnect('competing');
    }
});

it('imports material counts at their actual timestamp while preserving later consumption', function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    $f = businessFixture();
    app(StockLedger::class)->moveMaterial($f['material']->id, $f['materialWarehouse']->id, -50, MaterialMovementType::Adjustment, movedAt: '2026-10-20 10:00:00');
    $path = tempnam(sys_get_temp_dir(), 'count-');
    file_put_contents($path, "KODE GUDANG,KODE BAHAN,STOK FISIK,TANGGAL OPNAME\nMAT,FAB,980,2026-10-20 09:00:00\n");
    try {
        $result = app(MaterialStockCountImport::class)->import($path, 'count.csv');
        expect($result['errors'])->toBe([]);
        expect((float) app(StockLedger::class)->materialQty($f['material']->id, $f['materialWarehouse']->id))->toBe(930.0);
    } finally {
        unlink($path);
    }
});

it('keeps posted opname counts immutable even when a stale editor saves a child', function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    $f = businessFixture();
    $opname = StockOpname::create(['warehouse_id' => $f['warehouse']->id, 'counted_at' => '2026-10-20 09:00:00']);
    $item = $opname->items()->create(['variant_id' => $f['variant']->id, 'system_qty' => 100, 'counted_qty' => 100]);
    app(InventoryService::class)->postOpname($opname);
    expect(fn () => $item->update(['counted_qty' => 200]))->toThrow(BusinessRuleException::class, 'tidak boleh diubah');
    expect(fn () => $opname->fresh()->delete())->toThrow(BusinessRuleException::class, 'tidak boleh diubah');
    expect($item->fresh()->counted_qty)->toBe(100);
});

it('does not backdate a stock mutation through an already posted physical count', function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    $f = businessFixture();
    $opname = StockOpname::create(['warehouse_id' => $f['warehouse']->id, 'counted_at' => '2026-10-20 09:00:00']);
    $opname->items()->create(['variant_id' => $f['variant']->id, 'system_qty' => 100, 'counted_qty' => 98]);
    app(InventoryService::class)->postOpname($opname);
    expect(fn () => app(StockLedger::class)->moveStock($f['variant']->id, $f['warehouse']->id, -1, StockMovementType::Adjustment, movedAt: '2026-10-20 08:00:00'))
        ->toThrow(BusinessRuleException::class, 'Sudah ada opname');
});

it('allocates an automatic invoice number when the form submits an empty number', function () {
    $f = businessFixture();
    $data = ['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'invoice_no' => ''];
    $first = app(InvoiceService::class)->generate($data);
    $second = app(InvoiceService::class)->generate($data);
    expect($first->invoice_no)->toBe('INV/202610/0001');
    expect($second->invoice_no)->toBe('INV/202610/0002');
});
