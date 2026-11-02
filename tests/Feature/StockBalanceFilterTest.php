<?php

use App\Enums\GoodsReceiptSource;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\StockBalances\Pages\ManageStockBalances;
use App\Imports\StockAdjustmentImport;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\InventoryService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('filters finished goods stock by source: production or ready made', function () {
    $f = businessFixture();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);

    $readyModel = ProductModel::create(['code' => 'RM', 'name' => 'Ready', 'category_id' => $f['category']->id, 'kind' => 'beli_jadi']);
    $ready = ProductVariant::create(['model_id' => $readyModel->id, 'sku' => 'RM-1', 'name' => 'Ready 1', 'is_active' => true]);
    app(InventoryService::class)->openingStock($f['variant']->id, $f['warehouse']->id, 5, 100, '2026-08-01');
    app(InventoryService::class)->openingStock($ready->id, $f['warehouse']->id, 3, 100, '2026-08-01');

    $produced = StockBalance::where('variant_id', $f['variant']->id)->sole();
    $bought = StockBalance::where('variant_id', $ready->id)->sole();

    Livewire::test(ManageStockBalances::class)
        ->assertCanSeeTableRecords([$produced, $bought])
        ->filterTable('kind', 'beli_jadi')
        ->assertCanSeeTableRecords([$bought])
        ->assertCanNotSeeTableRecords([$produced])
        ->filterTable('kind', 'produksi')
        ->assertCanSeeTableRecords([$produced])
        ->assertCanNotSeeTableRecords([$bought]);
});

it('imports a stock adjustment sheet exported with every SKU, skipping rows with QTY 0 or empty', function () {
    $f = businessFixture();
    $readyModel = ProductModel::create(['code' => 'RM', 'name' => 'Ready', 'category_id' => $f['category']->id, 'kind' => 'beli_jadi']);
    ProductVariant::create(['model_id' => $readyModel->id, 'sku' => 'RM-1', 'name' => 'Ready 1', 'is_active' => true]);
    ProductVariant::create(['model_id' => $readyModel->id, 'size_id' => Size::create(['code' => 'L', 'sort_order' => 3])->id, 'sku' => 'RM-2', 'name' => 'Ready 2', 'is_active' => true]);
    $path = tempnam(sys_get_temp_dir(), 'adj-').'.csv';
    file_put_contents($path, "TANGGAL,GUDANG,ALASAN,SKU VARIAN,QTY\n2026-11-01,GOODS,Koreksi,001234,4\n2026-11-01,GOODS,Koreksi,RM-1,0\n2026-11-01,GOODS,Koreksi,RM-2,\n");

    try {
        $result = app(StockAdjustmentImport::class)->import($path, 'penyesuaian.csv');
    } finally {
        @unlink($path);
    }

    expect($result['errors'])->toBe([])
        ->and(StockAdjustment::query()->sole()->items()->pluck('qty', 'variant_id')->all())->toBe([$f['variant']->id => 4]);
});

it('allows goods received at the exact time of an opening count, but not of a regular count', function () {
    $f = businessFixture();
    $inventory = app(InventoryService::class);
    $count = function (bool $opening, string $at) use ($f, $inventory): void {
        $opname = StockOpname::create(['warehouse_id' => $f['warehouse']->id, 'counted_at' => $at, 'status' => 'draft', 'is_opening' => $opening]);
        $opname->items()->create(['variant_id' => $f['variant']->id, 'system_qty' => 0, 'counted_qty' => 10]);
        $inventory->postOpname($opname);
    };
    $receive = fn (string $at) => $inventory->createGoodsReceipt(
        ['warehouse_id' => $f['warehouse']->id, 'received_at' => $at, 'source' => GoodsReceiptSource::Produksi],
        [['variant_id' => $f['variant']->id, 'qty' => 5, 'unit_cost' => 100]],
    );

    $count(true, '2026-08-01 00:00:00');
    $receive('2026-08-01');
    expect(StockBalance::where('variant_id', $f['variant']->id)->value('qty'))->toBe(15);

    $count(false, '2026-09-01 00:00:00');
    expect(fn () => $receive('2026-09-01'))->toThrow(BusinessRuleException::class, 'Sudah ada opname');
});
