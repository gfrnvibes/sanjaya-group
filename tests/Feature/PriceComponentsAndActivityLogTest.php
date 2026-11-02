<?php

use App\Enums\GoodsReceiptSource;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Owners\Pages\ListOwners;
use App\Filament\Resources\VariantPriceComponents\Pages\ListPriceComponents;
use App\Filament\Resources\VariantPriceComponents\Pages\ListProducedProducts;
use App\Filament\Resources\VariantPriceComponents\Pages\ListReadyMadeProducts;
use App\Models\LaborType;
use App\Models\PriceTier;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Services\SalesOrderService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function priceAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));

    return $user;
}

it('shows price components with tier prices and filters by category', function () {
    $f = businessFixture();
    PriceTier::create(['code' => 'GOLD', 'name' => 'Gold', 'adjustment_pct' => 0.10]);
    $this->actingAs(priceAdmin());

    $this->get('/administrator/komponen-harga')->assertOk()->assertSee('Komponen Harga')->assertSee('Gold');

    Livewire::test(ListPriceComponents::class)
        ->assertCanSeeTableRecords([$f['variant']])
        ->assertTableColumnStateSet('hpp', 60.0, $f['variant'])
        ->assertTableColumnStateSet('base_price', 100.0, $f['variant'])
        ->assertTableColumnStateSet('tier_'.PriceTier::where('code', 'GOLD')->value('id'), 100.0, $f['variant'])
        ->filterTable('category', [$f['category']->id + 999])
        ->assertCanNotSeeTableRecords([$f['variant']]);
});

it('changes packing and margin of a ready made variant from an effective date without touching older orders', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 100]);
    $model = ProductModel::create(['code' => 'RM', 'name' => 'Ready', 'category_id' => $f['category']->id, 'kind' => 'beli_jadi']);
    $variant = ProductVariant::create(['model_id' => $model->id, 'sku' => 'RM-1', 'name' => 'Ready 1', 'is_active' => true]);
    app(InventoryService::class)->createGoodsReceipt(
        ['receipt_no' => 'BM-RM', 'warehouse_id' => $f['warehouse']->id, 'received_at' => '2026-08-01', 'source' => GoodsReceiptSource::BeliJadi],
        [['variant_id' => $variant->id, 'qty' => 50, 'unit_cost' => 40000]],
    );
    app(PricingService::class)->syncForReadyMade([$variant->id], '2026-08-01');
    $old = app(SalesOrderService::class)->create(['store_id' => $f['store']->id, 'warehouse_id' => $f['warehouse']->id, 'order_date' => '2026-08-05'], [['variant_id' => $variant->id, 'qty' => 1]])->items()->sole();
    $this->actingAs(priceAdmin());

    // (40.000 + 2.000 default) x 1,05 = 44.100
    expect((float) $old->reseller_unit_price)->toBe(44100.0);

    Livewire::test(ListReadyMadeProducts::class)
        ->assertCanSeeTableRecords([$variant])
        ->assertCanNotSeeTableRecords([$f['variant']])
        ->callAction(TestAction::make('changePrice')->table($variant), data: ['margin' => 10, 'packing' => 1000, 'effective_from' => '2026-08-10'])
        ->assertHasNoFormErrors();

    // (40.000 + 1.000) x 1,10 = 45.100 mulai 10/08; pesanan 05/08 tetap harga lama.
    $variant->refresh();
    expect((float) $variant->margin_pct)->toBe(0.1)
        ->and(app(CostingService::class)->packingCostFor($variant, '2026-08-09'))->toBe(2000.0)
        ->and(app(CostingService::class)->packingCostFor($variant, '2026-08-10'))->toBe(1000.0)
        ->and($variant->priceHistories()->orderBy('effective_from')->get()->map(fn ($price): array => [$price->effective_from->toDateString(), (float) $price->base_price])->all())
        ->toBe([['2026-08-01', 44100.0], ['2026-08-10', 45100.0]])
        ->and((float) $old->fresh()->reseller_unit_price)->toBe(44100.0);

    $new = app(SalesOrderService::class)->create(['store_id' => $f['store']->id, 'warehouse_id' => $f['warehouse']->id, 'order_date' => '2026-08-12'], [['variant_id' => $variant->id, 'qty' => 1]])->items()->sole();
    expect((float) $new->reseller_unit_price)->toBe(45100.0);

    // Perubahan terjadwal (tanggal mendatang): margin 20% mulai 01/09 -> 41.000 x 1,2 = 49.200, harga otomatis.
    Livewire::test(ListReadyMadeProducts::class)
        ->callAction(TestAction::make('changePrice')->table($variant), data: ['margin' => 20, 'packing' => 1000, 'effective_from' => '2026-09-01'])
        ->assertHasNoFormErrors();
    $scheduled = $variant->priceHistories()->whereDate('effective_from', '2026-09-01')->first();
    expect((float) $scheduled->base_price)->toBe(49200.0)
        ->and($scheduled->source->value)->toBe('auto');

    // Beli jadi: harga dasar manual boleh, dengan tanggal berlaku.
    Livewire::test(ListReadyMadeProducts::class)
        ->callAction(TestAction::make('changePrice')->table($variant), data: ['margin' => 20, 'packing' => 1000, 'manual_price' => 50000, 'note' => 'Harga promo', 'effective_from' => '2026-09-15'])
        ->assertHasNoFormErrors();
    $manual = $variant->priceHistories()->whereDate('effective_from', '2026-09-15')->first();
    expect((float) $manual->base_price)->toBe(50000.0)
        ->and($manual->source->value)->toBe('manual')
        ->and($manual->note)->toBe('Harga promo');

    // Produksi: harga manual ditolak (HPP & harga hanya dari BOM).
    expect(fn () => app(PricingService::class)->changePrice($f['variant'], [], '2026-09-15', 99999))
        ->toThrow(BusinessRuleException::class, 'hanya dihitung dari resep');
});

it('adds dated packing to produced products and shows both product menus', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 1]);
    $this->actingAs(priceAdmin());

    app(PricingService::class)->changePrice($f['variant'], ['packing_cost' => 500], '2026-08-01');

    $cost = $f['variant']->costs()->whereDate('effective_from', '2026-08-01')->first();
    // Bahan 1 x 60 + packing 500
    expect((float) $cost->packing_cost)->toBe(500.0)
        ->and((float) $cost->total_cost)->toBe(560.0);

    $this->get('/administrator/produk-produksi')->assertOk()->assertSee('Produk Produksi')->assertSee('001234');
    $this->get('/administrator/produk-beli-jadi')->assertOk()->assertSee('Produk Beli Jadi')->assertDontSee('001234');
    Livewire::test(ListProducedProducts::class)
        ->assertCanSeeTableRecords([$f['variant']])
        ->assertTableColumnStateSet('packing', 500.0, $f['variant'])
        ->assertTableColumnStateSet('hpp_bom', 60.0, $f['variant'])
        ->assertTableColumnStateSet('bom', 'FAB 1', $f['variant']);
});

it('raises produced prices automatically when raw material purchase prices go up', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'price_rounding'], ['value' => 1]);
    app(PricingService::class)->syncVariants([$f['variant']], '2026-08-01');
    $before = (float) $f['variant']->priceHistories()->latest('effective_from')->value('base_price');

    app(InventoryService::class)->createMaterialPurchase(['warehouse_id' => $f['materialWarehouse']->id, 'purchase_date' => '2026-09-01'], [
        ['material_id' => $f['material']->id, 'qty_pack' => 1, 'qty_base' => 1000, 'total_price' => 90000],
    ]);
    app(PricingService::class)->syncForMaterials([$f['material']->id], '2026-09-01');

    $after = $f['variant']->priceHistories()->whereDate('effective_from', '2026-09-01')->value('base_price');
    // Bahan naik 60 -> 90 per cm: harga dasar = 90 x 1,30 = 117 mulai tanggal beli.
    expect($before)->toBe(78.0)->and((float) $after)->toBe(117.0);
});

it('lists business activity with the changed values for administrators only', function () {
    $f = businessFixture();
    $admin = priceAdmin();
    $this->actingAs($admin);
    $f['variant']->update(['name' => 'Nama Baru']);
    $activity = Activity::query()->where('event', 'updated')->latest('id')->first();

    $this->get('/administrator/log-aktivitas')->assertOk()->assertSee('Log Aktivitas');

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$activity])
        ->assertTableColumnStateSet('properties', 'name: Variant → Nama Baru', $activity)
        ->filterTable('event', ['deleted'])
        ->assertCanNotSeeTableRecords([$activity]);

    $staff = User::factory()->create(['is_active' => true]);
    $staff->givePermissionTo(Permission::findOrCreate('kelola katalog', 'web'));
    $this->actingAs($staff)->get('/administrator/log-aktivitas')->assertForbidden();
});

it('prints the visible table columns to a print page only the requesting user can open', function () {
    businessFixture();
    $admin = priceAdmin();
    $this->actingAs($admin);

    $component = Livewire::test(ListOwners::class)->callAction(TestAction::make('ioExport_print'));

    $token = collect(Cache::getStore() instanceof ArrayStore ? (fn () => $this->storage)->call(Cache::getStore()) : [])
        ->keys()->map(fn (string $key): string => str_replace('print:', '', $key))->first(fn (string $key): bool => strlen($key) === 36);

    expect($token)->not->toBeNull();
    $this->get("/administrator/cetak/{$token}")->assertOk()->assertSee('Cetak / Simpan PDF')->assertSee('OWN');
    $this->actingAs(priceAdmin())->get("/administrator/cetak/{$token}")->assertNotFound();
});

it('lists produced products whose recipe has no sewing labor', function () {
    $f = businessFixture();
    $this->actingAs(priceAdmin());

    Livewire::test(ListProducedProducts::class)
        ->filterTable('wage_check', 'no_sewing')
        ->assertCanSeeTableRecords([$f['variant']]);

    $sew = LaborType::create(['code' => 'JAHIT', 'name' => 'Jahit']);
    $f['bom']->items()->create(['line_type' => 'labor', 'labor_type_id' => $sew->id, 'labor_rate' => 4000, 'qty_per_unit' => 1]);

    Livewire::test(ListProducedProducts::class)
        ->filterTable('wage_check', 'no_sewing')
        ->assertCanNotSeeTableRecords([$f['variant']]);
});
