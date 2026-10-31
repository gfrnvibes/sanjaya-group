<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Filament Shield: role "admin" menjadi "super_admin" (semua akses), user admin tetap terhubung.
     * Izin lama (kelola penjualan, dst.) tetap ada sebagai izin khusus Shield.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $superAdmin = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();

        if ($admin !== null && $superAdmin === null) {
            $admin->update(['name' => 'super_admin']);
        } elseif ($admin !== null) {
            // super_admin sudah ada: pindahkan user & izin admin lalu hapus role lama.
            DB::table('model_has_roles')->where('role_id', $admin->id)->update(['role_id' => $superAdmin->id]);
            $superAdmin->givePermissionTo($admin->permissions);
            $admin->delete();
        } else {
            Role::findOrCreate('super_admin', 'web');
        }

        foreach (['kelola penjualan', 'kelola stok', 'kelola produksi', 'kelola keuangan', 'kelola katalog', 'kelola mitra', 'lihat laporan'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->update(['name' => 'admin']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
