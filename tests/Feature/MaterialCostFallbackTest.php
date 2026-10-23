<?php

use App\Models\Bom;
use App\Models\Setting;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PricingService;

it('recalculates prices from the recipe date when a material purchase is dated before the recipe', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 1]);
    // Resep baru berlaku 1 Agustus; harga awal dari stok awal katalog (Rp60/cm) => 60 x 1,30 = 78.
    $bom = Bom::create(['model_id' => $f['model']->id, 'effective_from' => '2026-08-01']);
    $bom->items()->create(['line_type' => 'material', 'material_type_id' => $f['material']->material_type_id, 'color_mode' => 'fixed', 'material_id' => $f['material']->id, 'qty_per_unit' => 1]);
    app(PricingService::class)->syncForBom($bom);
    Bom::query()->whereKeyNot($bom->id)->delete();

    // Pembelian 15 Juli (sebelum resep berlaku) Rp90/cm => harga mulai 1 Agustus menyesuaikan.
    app(InventoryService::class)->createMaterialPurchase(['warehouse_id' => $f['materialWarehouse']->id, 'purchase_date' => '2026-07-15'], [
        ['material_id' => $f['material']->id, 'qty_pack' => 1, 'qty_base' => 1000, 'total_price' => 90000],
    ]);
    $result = app(PricingService::class)->syncForMaterials([$f['material']->id], '2026-07-15');

    expect($result['skipped'])->toBe([])
        ->and((float) $f['variant']->priceHistories()->whereDate('effective_from', '2026-08-01')->value('base_price'))->toBe(117.0);
});

it('prices a recipe dated before the first known material price with that earliest price', function () {
    $f = businessFixture();
    // Resep berlaku sebelum stok awal bahan dicatat (stok awal fixture: 2026-01-01, Rp60 per cm).
    $bom = Bom::create(['model_id' => $f['model']->id, 'effective_from' => '2025-12-01']);
    $bom->items()->create(['line_type' => 'material', 'material_type_id' => $f['material']->material_type_id, 'color_mode' => 'fixed', 'material_id' => $f['material']->id, 'qty_per_unit' => 2]);

    $cost = app(CostingService::class)->calculate($f['variant'], '2025-12-01');
    $result = app(PricingService::class)->syncForBom($bom);

    expect($cost['material_cost'])->toBe(120.0)
        ->and($result['skipped'])->toBe([])
        ->and($result['synced'])->toBe(1)
        ->and($f['variant']->priceHistories()->whereDate('effective_from', '2025-12-01')->exists())->toBeTrue();
});
