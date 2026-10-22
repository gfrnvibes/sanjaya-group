<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeder hanya boleh dijalankan pada local/testing.');
        }

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            MasterDataSeeder::class,
            ProductSeeder::class,
            CostingSeeder::class,
            InventorySeeder::class,
            ProductionSeeder::class,
            SalesSeeder::class,
            FinanceSeeder::class,
            StockOpnameSeeder::class,
        ]);

        $this->call(AccessSeeder::class);
        $user->assignRole('admin');
    }
}
