<?php

use App\Models\User;
use App\Support\ApplicationAccess;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AdminPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('creates the admin role with every application permission', function () {
    $this->seed(AdminPermissionSeeder::class);

    $admin = Role::findByName('super_admin', 'web');

    foreach (ApplicationAccess::PERMISSIONS as $name) {
        expect($admin->hasPermissionTo($name))->toBeTrue();
    }
});

it('includes custom web permissions without changing other roles or guards on repeated runs', function () {
    $permission = Permission::findOrCreate('ekspor data', 'web');
    $apiPermission = Permission::findOrCreate('akses api', 'api');
    $staff = Role::findOrCreate('gudang', 'web');
    $staff->givePermissionTo($permission);
    $apiAdmin = Role::findOrCreate('admin', 'api');
    $apiAdmin->givePermissionTo($apiPermission);

    $this->seed(AdminPermissionSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    expect(Role::findByName('super_admin', 'web')->permissions->pluck('name')->all())
        ->toContain('ekspor data')->not->toContain('akses api');
    expect($staff->fresh()->permissions->pluck('name')->all())->toBe(['ekspor data']);
    expect($apiAdmin->fresh()->permissions->pluck('name')->all())->toBe(['akses api']);
    $this->assertDatabaseCount('roles', 3);
    $this->assertDatabaseCount('permissions', count(ApplicationAccess::PERMISSIONS) + 2);
});

it('grants custom permissions to admin through the access seeder', function () {
    Permission::findOrCreate('ekspor data', 'web');

    $this->seed(AccessSeeder::class);

    expect(Role::findByName('super_admin', 'web')->hasPermissionTo('ekspor data'))->toBeTrue();
    expect(User::where('email', config('admin.email'))->firstOrFail()->hasPermissionTo('ekspor data'))->toBeTrue();
});
