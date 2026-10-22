<?php

namespace Database\Seeders;

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
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeder hanya boleh dijalankan pada local/testing.');
        }

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
    }
}
