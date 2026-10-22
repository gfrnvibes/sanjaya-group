<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

it('creates an active staff admin with configured credentials and all web permissions', function () {
    config(['admin.name' => 'Admin Sanjaya', 'admin.email' => 'admin@sanjaya.test', 'admin.password' => 'admin-test-secret']);
    Permission::findOrCreate('ekspor data', 'web');

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', 'admin@sanjaya.test')->firstOrFail();
    expect($admin->name)->toBe('Admin Sanjaya');
    expect($admin->is_active)->toBeTrue();
    expect($admin->isKonveksiStaff())->toBeTrue();
    expect(Hash::check('admin-test-secret', $admin->password))->toBeTrue();
    expect($admin->hasRole('admin'))->toBeTrue();
    expect($admin->getAllPermissions()->pluck('id')->sort()->values()->all())
        ->toBe(Permission::where('guard_name', 'web')->orderBy('id')->pluck('id')->all());
});

it('preserves the existing password and picks up new permissions when repeated', function () {
    config(['admin.email' => 'existing@sanjaya.test', 'admin.password' => 'replacement-password']);
    $admin = User::factory()->create(['email' => 'existing@sanjaya.test', 'password' => 'original-password', 'is_active' => false]);
    $originalHash = $admin->password;
    $this->seed(AdminSeeder::class);
    Permission::findOrCreate('izin baru', 'web');

    $this->seed(AdminSeeder::class);

    $admin->refresh();
    $this->assertDatabaseCount('users', 1);
    expect($admin->password)->toBe($originalHash);
    expect($admin->is_active)->toBeTrue();
    expect($admin->hasPermissionTo('izin baru'))->toBeTrue();
});

it('uses the local demo password when no password is configured', function () {
    config(['admin.password' => null]);

    $this->seed(AdminSeeder::class);

    expect(Hash::check('password', User::where('email', config('admin.email'))->firstOrFail()->password))->toBeTrue();
});

it('requires a configured password before creating an admin in production', function () {
    config(['admin.password' => null]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(AdminSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Isi ADMIN_PASSWORD');

    $this->assertDatabaseMissing('users', ['email' => config('admin.email')]);
});
