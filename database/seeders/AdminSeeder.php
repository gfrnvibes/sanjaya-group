<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = User::firstOrNew(['email' => config('admin.email')]);

            if (! $admin->exists) {
                $password = config('admin.password');

                if (! is_string($password) || $password === '') {
                    if (! app()->environment(['local', 'testing'])) {
                        throw new \RuntimeException('Isi ADMIN_PASSWORD sebelum membuat akun admin.');
                    }

                    $password = 'password';
                }

                $admin->password = $password;
            }

            $admin->fill([
                'name' => config('admin.name'),
                'is_active' => true,
                'owner_id' => null,
            ])->save();

            $this->call(AdminPermissionSeeder::class);

            $admin->assignRole('admin');
        });
    }
}
