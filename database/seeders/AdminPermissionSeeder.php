<?php

namespace Database\Seeders;

use App\Support\ApplicationAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (ApplicationAccess::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            // Super admin (Filament Shield) memegang semua izin; akses penuhnya juga dijamin lewat Gate.
            Role::findOrCreate(ApplicationAccess::superAdminRole(), 'web')->syncPermissions(
                Permission::query()->where('guard_name', 'web')->get(),
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
