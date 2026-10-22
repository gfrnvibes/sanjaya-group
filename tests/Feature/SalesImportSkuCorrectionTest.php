<?php

use App\Enums\ImportFailureStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Models\SalesImportFailure;
use App\Models\SkuAlias;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SalesOrderImporter;
use App\Services\StockLedger;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @return array{0: SalesImportFailure, 1: string} */
function missingSkuFailure(array $fixture): array
{
    $csv = "TANGGAL,NAMA TOKO,SKU BIG SELLER,QTY,PENDAPATAN TOKO\n2026-10-01,Shop,UNKNOWN-SKU,2,300\n";
    $path = tempnam(sys_get_temp_dir(), 'missing-sku-');
    file_put_contents($path, $csv);
    try {
        $result = app(SalesOrderImporter::class)->import($path, 'missing.csv', ['warehouse_id' => $fixture['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);

        return [SalesImportFailure::query()->where('batch_id', $result['batch']->id)->sole(), $csv];
    } finally {
        unlink($path);
    }
}

it('corrects a missing SKU directly from the failure table and makes the order billable', function () {
    $f = businessFixture(0);
    [$failure] = missingSkuFailure($f);
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($user);

    Livewire::test(ListSalesImportFailures::class)
        ->callAction(TestAction::make('correctSku')->table($failure), data: ['sku' => '001234', 'warehouse_id' => $f['warehouse']->id, 'status' => 'completed'])
        ->assertHasNoActionErrors();

    expect($failure->fresh()->status)->toBe(ImportFailureStatus::Fixed);
    $this->assertDatabaseCount('sales_orders', 1);
    $this->assertDatabaseHas('sales_order_items', ['variant_id' => $f['variant']->id, 'qty' => 2, 'revenue' => 300]);
    $this->assertDatabaseCount('product_variants', 1);
    $this->assertDatabaseCount('sku_aliases', 0);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
    $invoice = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    expect($invoice->netTotal())->toBe(200.0);
});

it('retains the latest error and corrected input if a direct retry still fails', function () {
    $f = businessFixture();
    [$failure] = missingSkuFailure($f);
    $options = ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed'];

    $result = app(SalesOrderImporter::class)->retryFailure($failure, 'STILL-WRONG', $options);

    expect($result['errors'][0])->toContain('STILL-WRONG');
    expect($failure->fresh()->status)->toBe(ImportFailureStatus::Retried);
    $this->assertDatabaseCount('sales_orders', 0);
    $next = SalesImportFailure::query()->where('batch_id', $result['batch']->id)->sole();
    expect($next->value('SKU VARIAN'))->toBe('STILL-WRONG');
    expect($next->status)->toBe(ImportFailureStatus::Open);

    $fixed = app(SalesOrderImporter::class)->retryFailure($next, '001234', $options);
    expect($fixed['errors'])->toBe([]);
    expect($failure->fresh()->status)->toBe(ImportFailureStatus::Fixed);
    expect($next->fresh()->status)->toBe(ImportFailureStatus::Fixed);
    $this->assertDatabaseCount('sales_orders', 1);
});

it('prevents a stale second correction from creating another order', function () {
    $f = businessFixture(0);
    [$failure] = missingSkuFailure($f);
    $options = ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed'];
    app(SalesOrderImporter::class)->retryFailure($failure, '001234', $options);

    expect(fn () => app(SalesOrderImporter::class)->retryFailure($failure, '001234', $options))
        ->toThrow(BusinessRuleException::class, 'sudah ditangani');
    $this->assertDatabaseCount('sales_orders', 1);
    expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
});

it('recognizes the original file after a direct correction when its missing alias is later mapped', function () {
    $f = businessFixture(0);
    [$failure, $csv] = missingSkuFailure($f);
    $options = ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'manual'];
    app(SalesOrderImporter::class)->retryFailure($failure, '001234', $options);
    SkuAlias::create(['alias' => 'UNKNOWN-SKU', 'variant_id' => $f['variant']->id, 'source' => 'bigseller']);
    $path = tempnam(sys_get_temp_dir(), 'replay-sku-');
    file_put_contents($path, $csv);
    try {
        $result = app(SalesOrderImporter::class)->import($path, 'renamed.csv', $options);
        expect($result['errors'])->toBe([]);
        expect($result['skipped'])->toBe(1);
        $this->assertDatabaseCount('sales_orders', 1);
        expect(app(StockLedger::class)->stockQty($f['variant']->id, $f['warehouse']->id))->toBe(-2);
    } finally {
        unlink($path);
    }
});

it('denies inventory-only staff access to the failed sales correction page', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permission::findOrCreate('kelola stok', 'web'));
    $this->actingAs($user)->get('/administrator/import-gagal')->assertForbidden();
});
