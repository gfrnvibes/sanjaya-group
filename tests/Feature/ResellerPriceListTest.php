<?php

use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\VariantPriceComponents\Pages\ListPriceComponents;
use App\Models\PriceTier;
use App\Models\ResellerPriceHistory;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerPriceList;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Setting::updateOrCreate(['key' => 'tier_price_rounding'], ['value' => 1]);
    $this->f = businessFixture();
    $this->basic = PriceTier::create(['code' => 'T1', 'name' => 'Tier 1', 'adjustment_pct' => 0]);
    $this->premium = PriceTier::create(['code' => 'TIER 2', 'name' => 'Target Penjualan Offline', 'adjustment_pct' => 0.10]);
});

it('lists active products with their reseller price per tier on a given date, without cost or margin', function () {
    ResellerPriceHistory::create(['variant_id' => $this->f['variant']->id, 'effective_from' => '2026-12-01', 'base_price' => 200, 'variant_cost_id' => $this->f['cost']->id, 'source' => 'manual']);

    $list = app(ResellerPriceList::class)->build([$this->premium->id], Carbon::parse('2026-11-15'));
    $row = $list['groups']['Clothes']['Model']->sole();

    expect($list['tiers']->pluck('id')->all())->toBe([$this->premium->id])
        ->and($row->sku)->toBe('001234')
        ->and($row->prices)->toBe([$this->premium->id => 110.0]);

    expect(app(ResellerPriceList::class)->build([], Carbon::parse('2026-12-01'))['groups']['Clothes']['Model']->sole()->prices)
        ->toBe([$this->basic->id => 200.0, $this->premium->id => 220.0]);

    $html = app(ResellerPriceList::class)->html([$this->premium->id], Carbon::parse('2026-11-15'), [], 'Harga dapat berubah.');
    expect($html)->toContain('Daftar Harga Reseller')->toContain('<title>Daftar Harga Reseller — Tier 2 —')->toContain('Rp110')->toContain('Harga dapat berubah.')
        ->not->toContain('HPP')->not->toContain('Margin')->not->toContain('Target Penjualan')->not->toContain('+10%')->not->toContain('Tier:');

    $this->f['variant']->update(['is_active' => false]);
    expect(app(ResellerPriceList::class)->build([], Carbon::parse('2026-11-15'))['count'])->toBe(0);
});

it('opens the printable price list from the tier and price component pages', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);

    Livewire::test(ManagePriceTiers::class)
        ->callAction(TestAction::make('printTierPrices')->table($this->premium), ['date' => '2026-11-15'])
        ->assertHasNoFormErrors()
        ->callAction('printPriceList', ['tier_ids' => [$this->basic->id, $this->premium->id], 'date' => '2026-11-15'])
        ->assertHasNoFormErrors();

    Livewire::test(ListPriceComponents::class)
        ->callAction('printPriceList', ['date' => '2026-11-15'])
        ->assertHasNoFormErrors();
});
