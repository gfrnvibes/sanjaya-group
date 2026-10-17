<?php

namespace Database\Seeders;

use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Stok opname showroom (diposting, ada selisih) dan opname gudang utama (masih draft).
 */
class StockOpnameSeeder extends Seeder
{
    public function run(InventoryService $inventory): void
    {
        $showroom = StockOpname::create([
            'warehouse_id' => Warehouse::query()->where('code', 'SHOWROOM')->value('id'),
            'counted_at' => '2026-10-15 17:00:00',
            'note' => 'Opname bulanan showroom',
        ]);
        $inventory->fillOpnameFromStock($showroom);

        // Selisih fisik: 1 pcs hilang di item pertama.
        $first = $showroom->items()->orderBy('id')->first();
        $first?->update(['counted_qty' => max($first->system_qty - 1, 0)]);
        $inventory->postOpname($showroom->refresh());

        $main = StockOpname::create([
            'warehouse_id' => Warehouse::query()->where('code', 'UTAMA')->value('id'),
            'counted_at' => '2026-10-16 17:00:00',
            'note' => 'Opname gudang utama (sedang dihitung)',
        ]);
        $inventory->fillOpnameFromStock($main);

        foreach ($main->items()->orderBy('id')->limit(3)->get() as $index => $item) {
            $item->update(['counted_qty' => max($item->system_qty + [2, -1, -3][$index], 0)]);
        }
    }
}
