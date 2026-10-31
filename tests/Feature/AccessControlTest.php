<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Widgets\SalesDashboard\ProductionPriorityTable;
use App\Models\ResellerPayment;
use App\Models\User;
use App\Support\ApplicationAccess;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('rejects inactive and reseller accounts from the administrator panel', function (bool $active, bool $reseller) {
    $fixture = businessFixture();
    $user = User::factory()->create(['is_active' => $active, 'owner_id' => $reseller ? $fixture['owner']->id : null]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user)->get('/administrator/users')->assertForbidden();
})->with(['inactive staff' => [false, false], 'active reseller' => [true, true], 'inactive reseller' => [false, true]]);

it('restricts user management to administrators even when staff can manage inventory', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permission::findOrCreate('kelola stok', 'web'), Permission::findOrCreate('ViewAny:StockBalance', 'web'));
    $this->actingAs($user)->get('/administrator/users')->assertForbidden();
    $this->get('/administrator/shield/roles')->assertForbidden();
    $this->get('/administrator/settings')->assertForbidden();
    $this->get('/administrator/data-exchange')->assertForbidden();
    $this->get('/administrator/stock-balances')->assertOk();
});

it('gives super admins every page, including role management from Filament Shield', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($user)->get('/administrator/shield/roles')->assertOk();
    $this->get('/administrator/users')->assertOk();
    $this->get('/administrator/data-exchange')->assertOk();
    $this->get('/administrator/tagihan-supplier')->assertOk();
});

it('allows an active administrator to use the panel in production', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    config(['app.env' => 'production']);
    $this->actingAs($user)->get('/administrator/users')->assertOk();
});

it('serves payment proofs only to authorized finance staff', function () {
    Storage::set('local', Storage::fake('proof-private-'.Str::uuid()));
    $f = businessFixture();
    $payment = ResellerPayment::create(['reseller_id' => $f['reseller']->id, 'paid_at' => now(), 'amount' => 100, 'method' => 'cost_offset', 'proof_path' => 'payment-proofs/proof.png']);
    Storage::disk('local')->put($payment->proof_path, 'private proof');
    $url = '/administrator/payment-proofs/'.$payment->id;
    $this->get($url)->assertRedirect();
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user)->get($url)->assertForbidden();
    $user->givePermissionTo(Permission::findOrCreate('View:ResellerPayment', 'web'));
    $user->unsetRelation('permissions');
    $this->get($url)->assertOk()->assertDownload('proof.png');
});

it('records business changes without passwords in the audit log', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $this->actingAs($admin);
    $user = User::factory()->create(['name' => 'Before']);
    $user->update(['name' => 'After', 'password' => 'another-password']);
    $entry = Activity::query()->where('subject_id', $user->id)->where('subject_type', User::class)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($entry->properties['old']['name'])->toBe('Before');
    expect($entry->properties['attributes']['name'])->toBe('After');
    expect(json_encode($entry->properties))->not->toContain('password')->not->toContain('remember_token');
});

it('prevents report viewers from creating production orders through dashboard actions', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permission::findOrCreate('lihat laporan', 'web'));
    $this->actingAs($user);
    Livewire\Livewire::test(ProductionPriorityTable::class)
        ->assertActionHidden(TestAction::make('createWorkOrder')->table());
    expect(ApplicationAccess::allows('kelola produksi'))->toBeFalse();
});

it('moves old publicly stored payment proofs to private storage including orphan files', function () {
    Storage::set('public', Storage::fake('proof-public-'.Str::uuid()));
    Storage::set('local', Storage::fake('proof-private-'.Str::uuid()));
    Storage::disk('public')->put('payment-proofs/old.png', 'private bank statement');
    $this->artisan('payments:secure-proofs')->assertSuccessful();
    Storage::disk('public')->assertMissing('payment-proofs/old.png');
    Storage::disk('local')->assertExists('payment-proofs/old.png');
});

it('records role changes made from the user form', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $staff = User::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('gudang', 'web');
    $this->actingAs($admin);
    Livewire\Livewire::test(EditUser::class, ['record' => $staff->id])
        ->fillForm(['roles' => [$role->id]])->call('save')->assertHasNoFormErrors();
    expect($staff->fresh()->hasRole('gudang'))->toBeTrue();
    expect(Activity::query()->where('subject_type', User::class)->where('subject_id', $staff->id)->where('event', 'RoleAttachedEvent')->where('causer_id', $admin->id)->exists())->toBeTrue();
});
