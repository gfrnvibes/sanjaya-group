<?php

namespace Database\Seeders;

use App\Enums\GoodsReceiptSource;
use App\Enums\StockAdjustmentReason;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Stok awal (dari Excel), barang masuk (beli jadi & borongan), dan penyesuaian stok.
 */
class InventorySeeder extends Seeder
{
    public function run(InventoryService $inventory): void
    {
        $main = Warehouse::query()->where('code', 'UTAMA')->value('id');
        $showroom = Warehouse::query()->where('code', 'SHOWROOM')->value('id');
        $variant = fn (string $sku): int => ProductVariant::query()->where('sku', $sku)->value('id');

        // Stok awal barang jadi per 31 Juli (pengganti stok awal Excel).
        $opening = [
            'BC1HS' => 40, 'BC1HM' => 60, 'BC1HL' => 70, 'BC1HXL' => 30,
            'BC1PS' => 35, 'BC1PM' => 50, 'BC1PL' => 45, 'BC1PXL' => 20,
            'BC1NS' => 25, 'BC1NM' => 30, 'BC1NL' => 30, 'BC1NXL' => 18,
            'AP1H' => 40, 'AP1N' => 35, 'TC1P' => 20,
        ];

        foreach ($opening as $sku => $qty) {
            $inventory->openingStock($variant($sku), $main, $qty * 3, null, '2026-07-31 08:00:00', 'Stok awal dari Excel');
        }

        $inventory->openingStock($variant('BC1HM'), $showroom, 6, null, '2026-07-31 08:00:00', 'Stok display showroom');
        $inventory->openingStock($variant('AP1H'), $showroom, 4, null, '2026-07-31 08:00:00', 'Stok display showroom');

        // Sisa bahan lama per 31 Juli.
        $inventory->openingMaterial(Material::query()->where('code', 'DRILL - NAVY')->value('id'), $main, 1500, 140, '2026-07-31 08:00:00', 'Sisa kain dari Excel');
        $inventory->openingMaterial(Material::query()->where('code', 'KANCING CHEF')->value('id'), $main, 200, 300, '2026-07-31 08:00:00', 'Sisa kancing dari Excel');

        // Barang masuk: topi beli jadi & apron borongan.
        $inventory->createGoodsReceipt(
            [
                'receipt_no' => 'BM-260805-001',
                'warehouse_id' => $main,
                'received_at' => '2026-08-05 10:00:00',
                'source' => GoodsReceiptSource::BeliJadi,
                'supplier_id' => Supplier::query()->where('name', 'Mitra Topi Bandung')->value('id'),
                'note' => 'Topi chef putih beli jadi',
            ],
            [['variant_id' => $variant('TC1P'), 'qty' => 160, 'unit_cost' => 22000]],
        );
        $inventory->createGoodsReceipt(
            [
                'receipt_no' => 'BM-260912-001',
                'warehouse_id' => $main,
                'received_at' => '2026-09-12 14:00:00',
                'source' => GoodsReceiptSource::Borongan,
                'supplier_id' => Supplier::query()->where('name', 'Borongan Bu Tini')->value('id'),
                'note' => 'Apron borongan tambahan',
            ],
            [
                ['variant_id' => $variant('AP1H'), 'qty' => 110, 'unit_cost' => 31000],
                ['variant_id' => $variant('AP1N'), 'qty' => 18, 'unit_cost' => 31000],
            ],
        );

        // Penyesuaian stok.
        $inventory->createStockAdjustment(
            ['warehouse_id' => $main, 'adjusted_at' => '2026-08-20 16:00:00', 'reason' => StockAdjustmentReason::Rusak, 'note' => 'Noda tinta saat packing'],
            [['variant_id' => $variant('BC1PM'), 'qty' => -2], ['variant_id' => $variant('BC1PL'), 'qty' => -1]],
        );
        $inventory->createStockAdjustment(
            ['warehouse_id' => $main, 'adjusted_at' => '2026-09-03 09:00:00', 'reason' => StockAdjustmentReason::Sampel, 'note' => 'Sampel untuk foto katalog'],
            [['variant_id' => $variant('BC1NM'), 'qty' => -1], ['variant_id' => $variant('AP1N'), 'qty' => -1]],
        );
        $inventory->createStockAdjustment(
            ['warehouse_id' => $showroom, 'adjusted_at' => '2026-09-25 11:00:00', 'reason' => StockAdjustmentReason::Koreksi, 'note' => 'Pindahan dari gudang utama tercatat manual'],
            [['variant_id' => $variant('BC1HL'), 'qty' => 3]],
        );
    }
}
