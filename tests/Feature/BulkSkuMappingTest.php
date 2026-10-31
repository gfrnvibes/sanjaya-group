<?php

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Models\SalesImportFailure;
use App\Models\SalesOrder;
use App\Models\SkuAlias;
use App\Models\User;
use App\Services\SalesOrderImporter;
use App\Services\SkuMapping;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Baris gagal: UNKNOWN-A x2, UNKNOWN-B x1. */
function failedSkuRows(array $f): void
{
    $csv = "TANGGAL,NAMA TOKO,SKU TOKO,NAMA PRODUK,QTY,PENDAPATAN TOKO\n"
        ."2026-10-01,Shop,UNKNOWN-A,Produk A,1,150\n2026-10-02,Shop,UNKNOWN-A,Produk A,2,300\n2026-10-03,Shop,UNKNOWN-B,Produk B,1,150\n";
    $path = tempnam(sys_get_temp_dir(), 'bulk-sku-');
    file_put_contents($path, $csv);
    try {
        app(SalesOrderImporter::class)->import($path, 'gagal.csv', ['warehouse_id' => $f['warehouse']->id, 'status' => 'completed', 'source' => 'manual']);
    } finally {
        unlink($path);
    }
}

it('lists each failed marketplace SKU once with its row count, most rows first', function () {
    failedSkuRows(businessFixture());

    $skus = app(SkuMapping::class)->unmappedSkus();

    expect(array_map(fn (array $sku): array => [$sku['alias'], $sku['product'], $sku['count']], $skus))
        ->toBe([['UNKNOWN-A', 'Produk A', 2], ['UNKNOWN-B', 'Produk B', 1]]);
});

it('maps the ticked SKUs from the bulk screen and retries all of their orders at once', function () {
    $f = businessFixture();
    failedSkuRows($f);
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);

    Livewire::test(ListSalesImportFailures::class)
        ->callAction(TestAction::make('bulkMapSku'), data: [
            'skus' => [
                ['apply' => true, 'alias' => 'UNKNOWN-A', 'variant_id' => $f['variant']->id],
                ['apply' => false, 'alias' => 'UNKNOWN-B', 'variant_id' => null],
            ],
            'warehouse_id' => $f['warehouse']->id,
            'status' => 'completed',
        ])
        ->assertHasNoFormErrors();

    expect(SkuAlias::query()->pluck('variant_id', 'alias')->all())->toBe(['UNKNOWN-A' => $f['variant']->id])
        ->and(SalesOrder::query()->count())->toBe(2)
        ->and(SalesImportFailure::query()->where('status', ImportFailureStatus::Fixed)->count())->toBe(2)
        ->and(SalesImportFailure::query()->open()->sole()->rawSku())->toBe('UNKNOWN-B');
});

it('renders the bulk screen prefilled with one row per failed SKU', function () {
    $f = businessFixture();
    failedSkuRows($f);
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);

    $page = Livewire::test(ListSalesImportFailures::class)
        ->mountAction(TestAction::make('bulkMapSku'))
        ->assertActionMounted(TestAction::make('bulkMapSku'));
    $rows = collect($page->get('mountedActions.0.data.skus'))->values();

    expect($rows->map(fn (array $row): array => [$row['alias'], $row['item'], $row['count']])->all())
        ->toBe([['UNKNOWN-A', 'Produk A', 2], ['UNKNOWN-B', 'Produk B', 1]]);
});

it('requires a variant for every ticked SKU', function () {
    $f = businessFixture();
    failedSkuRows($f);
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);

    Livewire::test(ListSalesImportFailures::class)
        ->callAction(TestAction::make('bulkMapSku'), data: [
            'skus' => [['apply' => true, 'alias' => 'UNKNOWN-A', 'variant_id' => null]],
            'warehouse_id' => $f['warehouse']->id,
            'status' => 'completed',
        ])
        ->assertHasFormErrors();

    expect(SkuAlias::query()->count())->toBe(0);
});

function actingAsAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($user);

    return $user;
}

function actingAsSalesStaff(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('penjualan', 'web')->givePermissionTo(Permission::findOrCreate('kelola penjualan', 'web')));
    test()->actingAs($user);

    return $user;
}

it('maps only the SKUs of the selected rows from the table bulk action', function () {
    $f = businessFixture();
    failedSkuRows($f);
    actingAsAdmin();
    $selected = SalesImportFailure::query()->open()->get()->filter(fn (SalesImportFailure $failure): bool => $failure->rawSku() === 'UNKNOWN-B');

    $page = Livewire::test(ListSalesImportFailures::class)
        ->selectTableRecords($selected->modelKeys())
        ->mountAction(TestAction::make('bulkMapSkuSelected')->table()->bulk());

    expect(collect($page->get('mountedActions.0.data.skus'))->pluck('alias')->values()->all())->toBe(['UNKNOWN-B']);

    $key = array_key_first($page->get('mountedActions.0.data.skus'));
    $page->fillForm(["skus.{$key}.apply" => true, "skus.{$key}.variant_id" => $f['variant']->id, 'warehouse_id' => $f['warehouse']->id, 'status' => 'completed'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(SkuAlias::query()->pluck('alias')->all())->toBe(['UNKNOWN-B'])
        ->and(SalesImportFailure::query()->open()->get()->map->rawSku()->unique()->values()->all())->toBe(['UNKNOWN-A']);
});

it('ticks or clears every SKU that has a variant with the select switch', function () {
    $f = businessFixture();
    failedSkuRows($f);
    actingAsAdmin();

    $page = Livewire::test(ListSalesImportFailures::class)->mountAction(TestAction::make('bulkMapSku'));
    $keys = array_keys($page->get('mountedActions.0.data.skus'));
    $page->fillForm(collect($keys)->mapWithKeys(fn (string $key): array => ["skus.{$key}.variant_id" => $f['variant']->id])->all());

    $page->fillForm(['select' => 'all']);
    expect(collect($page->get('mountedActions.0.data.skus'))->pluck('apply')->all())->toBe([true, true]);

    $page->fillForm(['select' => 'none']);
    expect(collect($page->get('mountedActions.0.data.skus'))->pluck('apply')->all())->toBe([false, false]);
});

it('lets only admins delete failed rows', function () {
    $f = businessFixture();
    failedSkuRows($f);
    $failure = SalesImportFailure::query()->open()->firstOrFail();

    $staff = actingAsSalesStaff();
    expect(Gate::forUser($staff)->allows('delete', $failure))->toBeFalse();
    Livewire::test(ListSalesImportFailures::class)
        ->assertActionHidden(TestAction::make('delete')->table($failure))
        ->assertActionHidden(TestAction::make('delete')->table()->bulk())
        ->assertActionVisible(TestAction::make('bulkMapSkuSelected')->table()->bulk());

    actingAsAdmin();
    Livewire::test(ListSalesImportFailures::class)
        ->callAction(TestAction::make('delete')->table($failure));
    expect(SalesImportFailure::query()->whereKey($failure->id)->exists())->toBeFalse();

    Livewire::test(ListSalesImportFailures::class)
        ->selectTableRecords(SalesImportFailure::query()->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());
    expect(SalesImportFailure::query()->count())->toBe(0);
});

it('fixes rows whose SKU was mapped elsewhere as soon as the page opens', function () {
    $f = businessFixture();
    failedSkuRows($f);
    actingAsAdmin();
    SkuAlias::withoutEvents(fn () => SkuAlias::create(['alias' => 'UNKNOWN-A', 'variant_id' => $f['variant']->id, 'source' => 'bigseller']));

    expect(collect(app(SkuMapping::class)->unmappedSkus())->pluck('alias')->all())->toBe(['UNKNOWN-B']);

    Livewire::test(ListSalesImportFailures::class)->assertOk();

    expect(SalesOrder::query()->count())->toBe(2)
        ->and(SalesImportFailure::query()->where('status', ImportFailureStatus::Fixed)->count())->toBe(2)
        ->and(SalesImportFailure::query()->open()->sole()->rawSku())->toBe('UNKNOWN-B');
});

it('retries matching failed rows after a mapping is saved from any menu', function () {
    $f = businessFixture();
    failedSkuRows($f);
    actingAsAdmin();

    SkuAlias::create(['alias' => 'unknown-a', 'variant_id' => $f['variant']->id, 'source' => 'bigseller']);
    expect(SalesOrder::query()->count())->toBe(0); // dijalankan setelah respons terkirim

    app()->terminate();

    expect(SalesOrder::query()->count())->toBe(2)
        ->and(SalesImportFailure::query()->open()->count())->toBe(1);
});

it('opens the page and lists SKUs when there are no failed SKU rows', function () {
    businessFixture();
    actingAsAdmin();

    expect(app(SkuMapping::class)->unmappedSkus())->toBe([])
        ->and(app(SkuMapping::class)->plan())->toBe([]);

    Livewire::test(ListSalesImportFailures::class)->assertOk();
});

it('explains instead of opening an empty form when the selected rows need no SKU mapping', function () {
    $f = businessFixture();
    failedSkuRows($f);
    actingAsAdmin();
    SalesImportFailure::query()->update(['reason' => ImportFailureReason::PriceMissing]);

    Livewire::test(ListSalesImportFailures::class)
        ->selectTableRecords(SalesImportFailure::query()->pluck('id')->all())
        ->callAction(TestAction::make('bulkMapSkuSelected')->table()->bulk())
        ->assertNotified('Tidak ada SKU yang perlu dipetakan di baris yang dipilih');

    expect(SkuAlias::query()->count())->toBe(0);
});
