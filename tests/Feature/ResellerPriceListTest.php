<?php

use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\VariantPriceComponents\Pages\ListPriceComponents;
use App\Models\Color;
use App\Models\PriceTier;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\ResellerPriceHistory;
use App\Models\Setting;
use App\Models\Size;
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

    $list = app(ResellerPriceList::class)->build([$this->premium->id], Carbon::parse('2026-11-15'), layout: ResellerPriceList::BY_SKU);
    $row = $list['groups']['Clothes']['Model']->sole();

    expect($list['tiers']->pluck('id')->all())->toBe([$this->premium->id])
        ->and($row->sku)->toBe('001234')
        ->and($row->prices)->toBe([$this->premium->id => 110.0]);

    expect(app(ResellerPriceList::class)->build([], Carbon::parse('2026-12-01'), layout: ResellerPriceList::BY_MODEL)['groups']['Clothes']->sole()->lines->sole()->prices)
        ->toBe([$this->basic->id => 200.0, $this->premium->id => 220.0]);

    $html = app(ResellerPriceList::class)->html([$this->premium->id], Carbon::parse('2026-11-15'), [], 'Harga dapat berubah.');
    expect($html)->toContain('Daftar Harga Reseller')->toContain('<title>Daftar Harga Reseller — Tier 2 —')->toContain('Rp110')->toContain('Harga dapat berubah.')
        ->not->toContain('HPP')->not->toContain('Margin')->not->toContain('Target Penjualan')->not->toContain('+10%')->not->toContain('Tier:');

    $this->f['variant']->update(['is_active' => false]);
    expect(app(ResellerPriceList::class)->build([], Carbon::parse('2026-11-15'))['count'])->toBe(0);
});

it('prints one entry per model, splitting prices only where sizes differ', function () {
    $model = ProductModel::create(['code' => 'KM', 'name' => 'Kemeja', 'category_id' => $this->f['category']->id, 'kind' => 'produksi']);
    $colors = collect(['Navy', 'Hitam'])->map(fn (string $name) => Color::create(['code' => strtoupper(substr($name, 0, 2)), 'name' => $name]));
    $sizes = collect(['S', 'M', 'L', 'XL', '2XL'])->map(fn (string $code, int $i) => Size::create(['code' => $code, 'sort_order' => $i]));

    foreach ($colors as $color) {
        foreach ($sizes as $size) {
            $variant = ProductVariant::create(['model_id' => $model->id, 'color_id' => $color->id, 'size_id' => $size->id, 'name' => "Kemeja {$color->name} {$size->code}", 'is_active' => true]);
            ResellerPriceHistory::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'base_price' => $size->code === '2XL' ? 120 : 100, 'variant_cost_id' => $this->f['cost']->id, 'source' => 'manual']);
        }
    }

    $list = app(ResellerPriceList::class)->build([$this->basic->id], Carbon::parse('2026-11-15'), layout: ResellerPriceList::BY_MODEL);
    $kemeja = $list['groups']['Clothes']->firstWhere('code', 'KM');

    expect($list['count'])->toBe(2)
        ->and($kemeja->colors)->toBe(['Hitam', 'Navy'])
        ->and($kemeja->sizes)->toBe('S–2XL')
        ->and($kemeja->lines->map(fn ($line) => [$line->label, $line->prices[$this->basic->id]])->all())->toBe([['S–XL', 100.0], ['2XL', 120.0]]);

    $html = app(ResellerPriceList::class)->html([$this->basic->id], Carbon::parse('2026-11-15'), layout: ResellerPriceList::BY_MODEL);
    expect($html)->toContain('Kemeja')->toContain('S–XL')->not->toContain('Hitam')->not->toContain('Kemeja Navy S');

    $bySize = app(ResellerPriceList::class)->build([$this->basic->id], Carbon::parse('2026-11-15'), layout: ResellerPriceList::BY_SIZE)['groups']['Clothes'];
    expect($bySize['columns'])->toBe(['', 'S', 'M', 'L', 'XL', '2XL'])
        ->and($bySize['models']->firstWhere('code', 'KM')->bySize['2XL'][$this->basic->id])->toBe([120.0, 120.0])
        ->and(app(ResellerPriceList::class)->html([$this->basic->id], Carbon::parse('2026-11-15')))
        ->toContain('>2XL</th>')->toContain('<b>KM</b>')->toContain('Rp120')->not->toContain('Warna')->not->toContain('Hitam');

    expect(app(ResellerPriceList::class)->html([$this->basic->id], Carbon::parse('2026-11-15'), layout: ResellerPriceList::BY_SKU))
        ->toContain('Kemeja Navy S')->toContain('KMNAS');
});

it('opens the printable price list from the tier and price component pages', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);

    Livewire::test(ManagePriceTiers::class)
        ->callAction(TestAction::make('printTierPrices')->table($this->premium), ['date' => '2026-11-15'])
        ->assertHasNoFormErrors()
        ->callAction('printPriceList', ['tier_ids' => [$this->basic->id, $this->premium->id], 'date' => '2026-11-15', 'layout' => ResellerPriceList::BY_SKU])
        ->assertHasNoFormErrors();

    Livewire::test(ListPriceComponents::class)
        ->callAction('printPriceList', ['date' => '2026-11-15'])
        ->assertHasNoFormErrors();
});
