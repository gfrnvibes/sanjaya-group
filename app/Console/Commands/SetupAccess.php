<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ApplicationAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SetupAccess extends Command
{
    protected $signature = 'access:setup {--admin= : Email staf aktif yang akan menjadi super admin}';

    protected $description = 'Siapkan izin aplikasi dan tetapkan admin secara eksplisit';

    public function handle(): int
    {
        DB::transaction(function (): void {
            foreach (ApplicationAccess::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }
            foreach ([ApplicationAccess::superAdminRole() => ApplicationAccess::PERMISSIONS, 'gudang' => ['kelola stok', 'kelola produksi', 'kelola katalog'], 'keuangan' => ['kelola keuangan', 'lihat laporan'], 'reseller' => []] as $name => $permissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($permissions);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($email = $this->option('admin')) {
            $user = User::query()->where('email', $email)->whereNull('owner_id')->where('is_active', true)->first();
            if (! $user) {
                $this->error('Email harus milik staf aktif yang sudah terdaftar.');

                return self::FAILURE;
            }
            $user->assignRole(ApplicationAccess::superAdminRole());
            $this->info('Peran super admin diberikan kepada staf yang dipilih.');
        }
        $this->info('Izin aplikasi siap.');

        return self::SUCCESS;
    }
}
