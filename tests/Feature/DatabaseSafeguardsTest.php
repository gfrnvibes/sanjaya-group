<?php

use App\Enums\StockMovementType;
use App\Models\StockMovement;

it('allows negative finished goods balances at the database boundary and keeps the ledger balance exact', function () {
    $f = businessFixture(1);
    StockMovement::create(['variant_id' => $f['variant']->id, 'warehouse_id' => $f['warehouse']->id, 'qty' => -2, 'moved_at' => now(), 'movement_type' => StockMovementType::SaleOut]);
    $this->assertDatabaseHas('stock_balances', ['variant_id' => $f['variant']->id, 'warehouse_id' => $f['warehouse']->id, 'qty' => -1]);
    expect((int) StockMovement::query()->where('variant_id', $f['variant']->id)->sum('qty'))->toBe(-1);
});

it('requires a separate test database when preparing integration tests', function () {
    $this->artisan('test:prepare', ['--database' => 'sanjaya'])->assertFailed();
});
