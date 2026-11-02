<?php

use App\Filament\Resources\MaterialPurchases\Pages\CreateMaterialPurchase;
use App\Models\Bom;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PricingService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

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

it('prices a product from its recipe date on the first purchase of a new material and follows later price increases', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 1]);
    $model = ProductModel::create(['code' => 'SW1', 'name' => 'Semi Wool', 'category_id' => $f['category']->id, 'kind' => 'produksi']);
    $variant = ProductVariant::create(['model_id' => $model->id, 'name' => 'Semi Wool 1', 'is_active' => true]);
    $type = MaterialType::create(['code' => 'SW', 'name' => 'Semi Wool', 'kind' => 'kain']);
    $wool = Material::create(['material_type_id' => $type->id, 'code' => 'SW', 'base_uom' => 'cm', 'qty_per_pack' => 100, 'is_active' => true]);
    $bom = Bom::create(['model_id' => $model->id, 'effective_from' => '2026-08-01']);
    $bom->items()->create(['line_type' => 'material', 'material_type_id' => $type->id, 'color_mode' => 'fixed', 'material_id' => $wool->id, 'qty_per_unit' => 100]);

    $buy = function (string $date, float $total) use ($f, $wool): void {
        app(InventoryService::class)->createMaterialPurchase(['warehouse_id' => $f['materialWarehouse']->id, 'purchase_date' => $date], [
            ['material_id' => $wool->id, 'qty_pack' => 1, 'qty_base' => 100, 'total_price' => $total],
        ]);
        app(PricingService::class)->syncForMaterials([$wool->id], $date);
    };

    // Pembelian pertama 31 Okt (Rp300/cm): belum pernah ada harga, jadi berlaku sejak resep 1 Agustus. 100 cm x 300 x 1,3 = 39.000.
    $buy('2026-10-31', 30000);
    // Harga naik 10 Nov (Rp400/cm): mulai 10 Nov saja, harga Agustus tetap.
    $buy('2026-11-10', 40000);

    expect($variant->priceHistories()->orderBy('effective_from')->get()->map(fn ($p): array => [$p->effective_from->toDateString(), (float) $p->base_price])->all())
        ->toBe([['2026-08-01', 39000.0], ['2026-11-10', 52000.0]]);
});

it('shows the purchase unit in the material purchase form and converts yards to centimetres', function () {
    $f = businessFixture();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $f['material']->update(['pack_uom' => 'yard', 'qty_per_pack' => 91.44]);

    Livewire::test(CreateMaterialPurchase::class)
        ->assertSee('FAB (1 yard = 91,44 cm)')
        ->set('data.items', [['material_id' => $f['material']->id, 'qty_pack' => 1, 'qty_base' => null, 'total_price' => null]])
        ->set('data.items.0.qty_pack', 30)
        ->assertSet('data.items.0.qty_base', '2.743,2')
        ->assertSee('yard');
});
