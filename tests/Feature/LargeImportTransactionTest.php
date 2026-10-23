<?php

use App\Enums\ImportFailureStatus;
use App\Filament\Actions\SalesOrderImportActions;
use App\Imports\ImportRegistry;
use App\Models\SalesImportBatch;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SkuAlias;
use App\Services\SalesOrderImporter;

it('does not wrap the order import action in one panel transaction', function () {
    expect(SalesOrderImportActions::importAction()->hasDatabaseTransactions())->toBeFalse();
});

it('retries more failed order rows than one chunk in separate transactions and fixes them all', function () {
    $f = businessFixture();
    $rows = ImportRegistry::SALES_RETRY_CHUNK + 5;
    $csv = "TANGGAL,NAMA TOKO,SKU TOKO,QTY,PENDAPATAN TOKO\n".str_repeat("2026-10-01,Shop,UNKNOWN-SKU,1,150\n", $rows);
    $path = tempnam(sys_get_temp_dir(), 'large-');
    file_put_contents($path, $csv);
    try {
        app(SalesOrderImporter::class)->import($path, 'besar.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);
    } finally {
        unlink($path);
    }
    expect(SalesImportFailure::query()->open()->count())->toBe($rows);

    SkuAlias::create(['alias' => 'UNKNOWN-SKU', 'variant_id' => $f['variant']->id, 'source' => 'lainnya']);
    $batchesBefore = SalesImportBatch::query()->count();
    $result = ImportRegistry::retry(ImportRegistry::SALES_ORDERS, SalesImportFailure::query()->open()->get(), [], ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed']);

    expect($result['fixed'])->toBe($rows)
        ->and($result['errors'])->toBe([])
        ->and(SalesOrder::query()->count())->toBe($rows)
        ->and(SalesImportFailure::query()->where('status', ImportFailureStatus::Fixed)->count())->toBe($rows)
        ->and(SalesImportBatch::query()->count() - $batchesBefore)->toBe(2);
});
